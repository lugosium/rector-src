<?php declare(strict_types=1);

namespace Rector\Rector\MagicDisclosure;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use Rector\Node\NodeFactory;
use Rector\NodeAnalyzer\PropertyFetchAnalyzer;
use Rector\Rector\AbstractRector;

/**
 * __get to specific call
 *
 * Example - from:
 * - $container->someService;
 *
 * To
 * - $container->getService('someService');
 */
final class GetToMethodCallRector extends AbstractRector
{
    /**
     * @var string[]
     */
    private $typeToMethodCalls = [];

    /**
     * @var PropertyFetchAnalyzer
     */
    private $propertyAccessAnalyzer;

    /**
     * @var NodeFactory
     */
    private $nodeFactory;

    /**
     * @var string
     */
    private $activeMethod;

    /**
     * Type to method call()
     *
     * @param string[] $typeToMethodCalls
     */
    public function __construct(
        array $typeToMethodCalls,
        PropertyFetchAnalyzer $propertyAccessAnalyzer,
        NodeFactory $nodeFactory
    ) {
        $this->typeToMethodCalls = $typeToMethodCalls;
        $this->propertyAccessAnalyzer = $propertyAccessAnalyzer;
        $this->nodeFactory = $nodeFactory;
    }

    public function isCandidate(Node $node): bool
    {
        $this->activeMethod = null;

        foreach ($this->typeToMethodCalls as $type => $method) {
            if ($this->propertyAccessAnalyzer->isMagicPropertyFetchOnType($node, $type)) {
                $this->activeMethod = $method;
                return true;
            }
        }

        return false;
    }

    /**
     * @param PropertyFetch $propertyFetchNode
     */
    public function refactor(Node $propertyFetchNode): ?Node
    {
        $methodCall = $this->nodeFactory->createMethodCallWithVariable($propertyFetchNode->var, $this->activeMethod);

        $serviceName = $propertyFetchNode->name->name;
        $methodCall->args[] = $this->nodeFactory->createArg($serviceName);

        return $methodCall;
    }
}
