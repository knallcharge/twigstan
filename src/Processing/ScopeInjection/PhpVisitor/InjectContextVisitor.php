<?php

declare(strict_types=1);

namespace TwigStan\Processing\ScopeInjection\PhpVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Printer\Printer;
use TwigStan\Processing\ScopeInjection\ArrayShapeMerger;
use TwigStan\Processing\ScopeInjection\TwigScopeInjector;

/**
 * @phpstan-import-type ContextData from TwigScopeInjector
 */
final class InjectContextVisitor extends NodeVisitorAbstract
{
    /**
     * @param list<ContextData> $contextBeforeBlock
     */
    public function __construct(
        private readonly array $contextBeforeBlock,
        private readonly ArrayShapeMerger $arrayShapeMerger,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        // Search for the following pattern:
        //     // line 7
        //    /**
        //     * @param array{} $context
        //     * @return iterable<scalar>
        //     */
        //    public function block_main(array $context) : iterable

        if ( ! $node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $phpDoc = $node->getDocComment();

        if ($phpDoc === null) {
            return null;
        }

        if (preg_match('/^(?<parent>parent_)?block_(?<blockName>\w+)$/', $node->name->name, $match) === 1) {
            $contextBeforeBlock = $this->getContextBeforeBlock(
                $match['blockName'],
                $match['parent'] !== '',
            );

            $context = $contextBeforeBlock;
        } else {
            return null;
        }

        $node->setDocComment(
            new Doc(
                sprintf(
                    <<<'DOC'
                        /**
                         * @param %s $context
                         * @param array{} $blocks
                         * @return iterable<null|scalar|\Stringable>
                         */
                        DOC,
                    (new Printer())->print($context),
                ),
            ),
        );

        return $node;
    }

    private function getContextBeforeBlock(string $blockName, bool $parent): ArrayShapeNode
    {
        $context = null;
        foreach ($this->contextBeforeBlock as $contextBeforeBlock) {
            if ($contextBeforeBlock['blockName'] !== $blockName) {
                continue;
            }

            if ($contextBeforeBlock['parent'] !== $parent) {
                continue;
            }

            if ($context === null) {
                $context = $contextBeforeBlock['context'];

                continue;
            }

            $context = $this->arrayShapeMerger->merge($context, $contextBeforeBlock['context']);
        }

        return $context ?? ArrayShapeNode::createSealed([]);
    }
}
