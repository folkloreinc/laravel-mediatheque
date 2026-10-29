<?php

namespace Folklore\Mediatheque\Rector;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Facades\Mediatheque;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

/**
 * `$media->runPipeline($name)` becomes
 * `Mediatheque::runPipeline($media, $name)` (docs/upgrade.md).
 */
final class RunPipelineRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param  MethodCall  $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node->name, 'runPipeline') || $node->isFirstClassCallable() || ! $this->isObjectType($node->var, new ObjectType(Media::class))) {
            return null;
        }

        return new StaticCall(new FullyQualified(Mediatheque::class), 'runPipeline', [new Arg($node->var), ...$node->getArgs()]);
    }
}
