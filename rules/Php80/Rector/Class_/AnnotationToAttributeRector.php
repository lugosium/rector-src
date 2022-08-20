<?php

declare(strict_types=1);

namespace Rector\Php80\Rector\Class_;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PHPStan\PhpDocParser\Ast\Node as DocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTagRemover;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\Naming\Naming\UseImportsResolver;
use Rector\Php80\NodeFactory\AttrGroupsFactory;
use Rector\Php80\NodeManipulator\AttributeGroupNamedArgumentManipulator;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\Php80\ValueObject\DoctrineTagAndAnnotationToAttribute;
use Rector\PhpAttribute\NodeFactory\PhpAttributeGroupFactory;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\Astral\PhpDocParser\PhpDocNodeTraverser;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Webmozart\Assert\Assert;

/**
 * @changelog https://wiki.php.net/rfc/attributes_v2
 *
 * @see \Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\AnnotationToAttributeRectorTest
 */
final class AnnotationToAttributeRector extends AbstractRector implements ConfigurableRectorInterface, MinPhpVersionInterface
{
    /**
     * @var AnnotationToAttribute[]
     */
    private array $annotationsToAttributes = [];

    public function __construct(
        private readonly PhpAttributeGroupFactory $phpAttributeGroupFactory,
        private readonly AttrGroupsFactory $attrGroupsFactory,
        private readonly PhpDocTagRemover $phpDocTagRemover,
        private readonly AttributeGroupNamedArgumentManipulator $attributeGroupNamedArgumentManipulator,
        private readonly UseImportsResolver $useImportsResolver,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Change annotation to attribute', [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Component\Routing\Annotation\Route;

class SymfonyRoute
{
    /**
     * @Route("/path", name="action")
     */
    public function action()
    {
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use Symfony\Component\Routing\Annotation\Route;

class SymfonyRoute
{
    #[Route(path: '/path', name: 'action')]
    public function action()
    {
    }
}
CODE_SAMPLE
                ,
                [new AnnotationToAttribute('Symfony\Component\Routing\Annotation\Route')]
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            Class_::class,
            Property::class,
            Param::class,
            ClassMethod::class,
            Function_::class,
            Closure::class,
            ArrowFunction::class,
        ];
    }

    /**
     * @param Class_|Property|Param|ClassMethod|Function_|Closure|ArrowFunction $node
     */
    public function refactor(Node $node): ?Node
    {
        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($node);
        if (! $phpDocInfo instanceof PhpDocInfo) {
            return null;
        }

        $uses = $this->useImportsResolver->resolveBareUsesForNode($node);

        // 1. bare tags without annotation class, e.g. "@inject"
        $genericAttributeGroups = $this->processGenericTags($phpDocInfo);

        // 2. Doctrine annotation classes
        $annotationAttributeGroups = $this->processDoctrineAnnotationClasses($phpDocInfo, $uses);

        $attributeGroups = array_merge($genericAttributeGroups, $annotationAttributeGroups);
        if ($attributeGroups === []) {
            return null;
        }

        $attributeGroups = $this->attributeGroupNamedArgumentManipulator->processSpecialClassTypes($attributeGroups);
        $node->attrGroups = array_merge($node->attrGroups, $attributeGroups);

        return $node;
    }

    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration): void
    {
        Assert::allIsAOf($configuration, AnnotationToAttribute::class);
        $this->annotationsToAttributes = $configuration;
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::ATTRIBUTES;
    }

    /**
     * @return AttributeGroup[]
     */
    private function processGenericTags(PhpDocInfo $phpDocInfo): array
    {
        $attributeGroups = [];

        $phpDocNodeTraverser = new PhpDocNodeTraverser();
        $phpDocNodeTraverser->traverseWithCallable($phpDocInfo->getPhpDocNode(), '', function (DocNode $docNode) use (
            &$attributeGroups,
            $phpDocInfo
        ): ?int {
            if (! $docNode instanceof PhpDocTagNode) {
                return null;
            }

            if (! $docNode->value instanceof GenericTagValueNode) {
                return null;
            }

            $tag = trim($docNode->name, '@');

            // not a basic one
            if (str_contains($tag, '\\')) {
                return null;
            }

            foreach ($this->annotationsToAttributes as $annotationToAttribute) {
                $desiredTag = $annotationToAttribute->getTag();
                if ($desiredTag !== $tag) {
                    continue;
                }

                $attributeGroups[] = $this->phpAttributeGroupFactory->createFromSimpleTag($annotationToAttribute);

                $phpDocInfo->markAsChanged();
                return PhpDocNodeTraverser::NODE_REMOVE;
            }

            return null;
        });

        return $attributeGroups;
    }

    /**
     * @param Use_[] $uses
     * @return AttributeGroup[]
     */
    private function processDoctrineAnnotationClasses(PhpDocInfo $phpDocInfo, array $uses): array
    {
        if ($phpDocInfo->getPhpDocNode()->children === []) {
            return [];
        }

        $doctrineTagAndAnnotationToAttributes = [];

        foreach ($phpDocInfo->getPhpDocNode()->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if (! $phpDocChildNode->value instanceof DoctrineAnnotationTagValueNode) {
                continue;
            }

            $doctrineTagValueNode = $phpDocChildNode->value;
            $annotationToAttribute = $this->matchAnnotationToAttribute($doctrineTagValueNode);
            if (! $annotationToAttribute instanceof AnnotationToAttribute) {
                continue;
            }

            $doctrineTagAndAnnotationToAttributes[] = new DoctrineTagAndAnnotationToAttribute(
                $doctrineTagValueNode,
                $annotationToAttribute,
            );

            $this->phpDocTagRemover->removeTagValueFromNode($phpDocInfo, $doctrineTagValueNode);
        }

        return $this->attrGroupsFactory->create($doctrineTagAndAnnotationToAttributes, $uses);
    }

    private function matchAnnotationToAttribute(
        DoctrineAnnotationTagValueNode $doctrineAnnotationTagValueNode
    ): AnnotationToAttribute|null {
        foreach ($this->annotationsToAttributes as $annotationToAttribute) {
            if (! $doctrineAnnotationTagValueNode->hasClassName($annotationToAttribute->getTag())) {
                continue;
            }

            return $annotationToAttribute;
        }

        return null;
    }
}
