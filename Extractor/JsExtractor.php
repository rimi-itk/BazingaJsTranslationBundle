<?php

namespace Bazinga\Bundle\JsTranslationBundle\Extractor;

use Peast\Peast;
use Peast\Syntax\Exception;
use Peast\Syntax\Node\CallExpression;
use Peast\Syntax\Node\Identifier;
use Peast\Syntax\Node\MemberExpression;
use Peast\Syntax\Node\Node;
use Peast\Syntax\Node\Program;
use Peast\Syntax\Node\StringLiteral;
use Peast\Syntax\Node\TemplateLiteral;
use Peast\Syntax\SourceLocation;
use Peast\Traverser;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Translation\Extractor\AbstractFileExtractor;
use Symfony\Component\Translation\Extractor\ExtractorInterface;
use Symfony\Component\Translation\MessageCatalogue;

class JsExtractor extends AbstractFileExtractor implements ExtractorInterface, LoggerAwareInterface
{
    use LoggerTrait;
    use LoggerAwareTrait;

    /** @var string */
    private $prefix;

    /** @var MessageCatalogue */
    private $catalog;

    private $extensions = ['js', 'jsx'];

    private $translatorObjectNames = ['Translator'];
    private $translatorPropertyNames = ['trans', 'transChoice'];
    private $defaultDomain = 'messages';

    protected function canBeExtracted(string $file)
    {
        return $this->isFile($file) && in_array(pathinfo($file, PATHINFO_EXTENSION), $this->extensions, true);
    }

    protected function extractFromDirectory($directory)
    {
        $finder = new Finder();

        $finder->files();
        foreach ($this->extensions as $extension) {
            $finder->name(sprintf('*.%s', $extension));
        }

        return $finder->in($directory);
    }

    public function extract($resource, MessageCatalogue $catalog)
    {
        $this->catalog = $catalog;
        $files = $this->extractFiles($resource);
        foreach ($files as $file) {
            $this->extractFromFile($file);

            gc_mem_caches();
        }
    }

    public function setPrefix(string $prefix)
    {
        $this->prefix = $prefix;
    }

    private function extractFromFile(SplFileInfo $file)
    {
        $source = file_get_contents($file->getPathname());
        $options = [
            'sourceType' => Peast::SOURCE_TYPE_MODULE,
            'jsx' => true,
        ];

        $parser = Peast::latest($source, $options);
        try {
            $ast = $parser->parse();
            $this->extractFromAST($ast, $file);
        } catch (Exception $exception) {
            $this->warning('Error parsing {file}: {message} (at line {line}).', [
                'file' => $file->getPathname(),
                'message' => $exception->getMessage(),
                'line' => $exception->getLine(),
                'exception' => $exception,
            ]);
            $this->warning('Falling back to regex matching to extract translations.');

            // Fallback to regex match.
            $pattern = '@'
                .'(?<object>'
                .implode('|', array_map(static function ($name) {
                    return preg_quote($name, '@');
                }, $this->translatorObjectNames))
                .')'
                .'\.'
                .'(?<property>'
                .implode('|', array_map(static function ($name) {
                    return preg_quote($name, '@');
                }, $this->translatorPropertyNames))
                .')'
                .'\('
                .'(?:\'(?<message_single>([^\']|\\[\'])+)\'|"(?<message_double>([^"]|\\["])+)")'
                .'(?:'
                .'(?:\s*,\s*\d+)?' // Cardinality argument for transChoice.
                .'\s*,\s*'
                .'[^)]+' // Assume that placeholders do not contain `)`.
                .'\s*,\s*'
                .'(?:\'(?<domain_single>([^\']|\\[\'])+)\'|"(?<domain_double>([^"]|\\["])+)")'
                .')?'
                .'\)'

                .'@x';

            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $message = $match['message_single'] ?? $match['message_double'];
                    $domain = $match['domain_single'] ?? $match['domain_double'] ?? $this->defaultDomain;
                    $this->setMessage($message, $domain, $file);
                }
            }
        }
    }

    private function extractFromAST(Program $ast, SplFileInfo $file)
    {
        $traverser = new Traverser();
        $traverser->addFunction(function (Node $node) use ($file) {
            if ($node instanceof CallExpression) {
                $callee = $node->getCallee();
                if ($callee instanceof MemberExpression) {
                    $object = $callee->getObject();
                    if ($object instanceof Identifier) {
                        $objectName = $object->getName();
                        if (in_array($objectName, $this->translatorObjectNames, true)) {
                            $property = $callee->getProperty();
                            if ($property instanceof Identifier) {
                                $propertyName = $property->getName();
                                if (in_array($propertyName, $this->translatorPropertyNames, true)) {
                                    $arguments = $node->getArguments();
                                    if (count($arguments) > 0 && ($arguments[0] instanceof StringLiteral || $arguments[0] instanceof TemplateLiteral)) {
                                        $message = null;
                                        $key = $arguments[0];
                                        if ($key instanceof TemplateLiteral) {
                                            // Only literal templates are supported.
                                            if (1 === count($key->getQuasis())) {
                                                $message = $key->getQuasis()[0]->getValue();
                                            }
                                        } else {
                                            $message = $arguments[0]->getValue();
                                        }
                                        if (null !== $message) {
                                            // Get domain from last argument.
                                            $domain = (count($arguments) > 2 && end($arguments) instanceof StringLiteral) ? end($arguments)->getValue() : $this->defaultDomain;
                                            $this->setMessage($message, $domain, $file, $key->getLocation());
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        });
        $traverser->traverse($ast);
    }

    private function setMessage(string $message, string $domain, SplFileInfo $file, ?SourceLocation $location = null)
    {
        $this->catalog->set($message, $this->prefix.$message, $domain);
        $metadata = $this->catalog->getMetadata($message, $domain) ?? [];
        $filename = $file->getPathname();
        // Normalize filename.
        $source = preg_replace('{[\\\\/]+}', '/', $filename);
        if ($location) {
            $source .= ':'.$location->getStart()->getLine();
        }
        $metadata['sources'][] = $source;
        $this->catalog->setMetadata($message, $metadata, $domain);
    }

    public function log($level, $message, array $context = [])
    {
        if (null !== $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
