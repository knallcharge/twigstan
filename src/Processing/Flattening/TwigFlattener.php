<?php

declare(strict_types=1);

namespace TwigStan\Processing\Flattening;

use PhpParser\NodeTraverser;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use TwigStan\PHP\PrettyPrinter;
use TwigStan\PHP\StrictPhpParser;
use TwigStan\Processing\Compilation\CompilationResultCollection;
use TwigStan\Processing\Flattening\PhpVisitor\BlockMethodFindingVisitor;
use TwigStan\Processing\Flattening\PhpVisitor\InjectBlockMethodsFromParentVisitor;
use TwigStan\Processing\Flattening\PhpVisitor\InlineParentTemplateVisitor;
use TwigStan\Processing\Flattening\PhpVisitor\MainMethodFinderVisitor;
use TwigStan\Twig\Metadata\MetadataRegistry;
use TwigStan\Twig\SourceLocation;

final class TwigFlattener
{
    /**
     * @var array<string, list<string>>
     */
    private array $cachedBlocks = [];

    public function __construct(
        private readonly PrettyPrinter $prettyPrinter,
        private readonly Filesystem $filesystem,
        private readonly MetadataRegistry $metadataRegistry,
        private readonly StrictPhpParser $phpParser,
    ) {}

    /**
     * Flattens the template by inlining the parent template(s) and block(s).
     */
    public function flatten(CompilationResultCollection $collection, string $targetDirectory, int $run): FlatteningResultCollection
    {
        $targetDirectory = Path::join($targetDirectory, (string) $run);

        $this->filesystem->mkdir($targetDirectory);

        $results = new FlatteningResultCollection();
        $this->cachedBlocks = [];
        foreach ($collection as $compilationResult) {
            $metadata = $this->metadataRegistry->getMetadata($compilationResult->twigFilePath);

            if ($metadata->hasResolvableParents()) {
                foreach ($metadata->parents as $parent) {
                    $blocksNeededFromParent = array_diff(
                        $this->getRecursiveBlocks($parent),
                        $metadata->blocks,
                    );

                    $blocksNeededFromParent = array_combine(
                        array_map(
                            fn($block) => 'block_' . $block,
                            $blocksNeededFromParent,
                        ),
                        $blocksNeededFromParent,
                    );

                    foreach ($metadata->parentBlocks as $parentBlock) {
                        $blocksNeededFromParent['parent_block_' . $parentBlock] = $parentBlock;
                    }

                    // Inline the body of the `main` function from the parent template
                    if ( ! $results->hasTwigFileName($parent)) {
                        throw new RuntimeException(sprintf('Parent template %s not found in mapping.', $parent));
                    }

                    $parentTransformResult = $results->getByTwigFileName($parent);
                    $phpAst = $this->phpParser->parseFile($parentTransformResult->phpFile);
                    // Find `main` method in parent template
                    $mainMethodFinderVisitor = new MainMethodFinderVisitor();
                    // Find block methods in parent template
                    $blockMethodFinderVisitor = new BlockMethodFindingVisitor();
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor($mainMethodFinderVisitor);
                    $traverser->addVisitor($blockMethodFinderVisitor);
                    $traverser->traverse($phpAst);

                    $sourceLocation = new SourceLocation(
                        $metadata->filePath,
                        $metadata->parentLineNumber ?? 0,
                    );

                    $blockMethods = [];
                    foreach ($blocksNeededFromParent as $alias => $block) {
                        if ( ! isset($blockMethodFinderVisitor->blocks[$block])) {
                            continue;
                        }

                        $blockMethods[$alias] = [$blockMethodFinderVisitor->blocks[$block], $sourceLocation];
                    }

                    // Inline the parent statements into the child template
                    $phpAst = $this->phpParser->parseFile($compilationResult->phpFile);
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new InlineParentTemplateVisitor($mainMethodFinderVisitor->stmts));
                    $traverser->addVisitor(new InjectBlockMethodsFromParentVisitor($blockMethods));
                    $phpAst = $traverser->traverse($phpAst);

                    $phpSource = $this->prettyPrinter->prettyPrintFile($phpAst);

                    $phpFile = Path::join($targetDirectory, basename($compilationResult->phpFile));

                    $this->filesystem->dumpFile(
                        $phpFile,
                        $phpSource,
                    );

                    $results = $results->with(new FlatteningResult(
                        $compilationResult->twigFilePath,
                        $phpFile,
                    ));
                }

                continue;
            }

            $this->filesystem->copy(
                $compilationResult->phpFile,
                Path::join($targetDirectory, basename($compilationResult->phpFile)),
            );

            $results = $results->with(
                new FlatteningResult(
                    $compilationResult->twigFilePath,
                    Path::join(
                        $targetDirectory,
                        basename($compilationResult->phpFile),
                    ),
                ),
            );
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function getRecursiveBlocks(string $parent): array
    {
        if ( ! isset($this->cachedBlocks[$parent])) {
            $parentMetadata = $this->metadataRegistry->getMetadata($parent);

            if ([] === $parentMetadata->parents) {
                $this->cachedBlocks[$parent] = $parentMetadata->blocks;
            } else {
                $this->cachedBlocks[$parent] = array_values(array_unique(array_merge(
                    $parentMetadata->blocks,
                    $this->getRecursiveBlocks($parentMetadata->parents[0]),
                )));
            }
        }

        return $this->cachedBlocks[$parent];
    }
}
