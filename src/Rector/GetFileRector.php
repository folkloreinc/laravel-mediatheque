<?php

namespace Folklore\Mediatheque\Rector;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Upgrade\LegacyHandle;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

/**
 * `$media->getFile('thumbnails.0')` becomes `$media->file('thumbnails', 0)`,
 * and `$media->hasFile($handle)` becomes `$media->file($handle) !== null`
 * (docs/upgrade.md).
 */
final class GetFileRector extends AbstractRector
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
        if (! $this->isNames($node->name, ['getFile', 'hasFile']) || $node->isFirstClassCallable() || ! $this->isObjectType($node->var, new ObjectType(Media::class))) {
            return null;
        }

        $args = $node->getArgs();
        if (count($args) !== 1) {
            return null;
        }

        $handle = $args[0]->value;
        $newArgs = [new Arg($handle)];
        if ($handle instanceof String_) {
            $legacy = LegacyHandle::parse($handle->value);
            $newArgs = [new Arg(new String_($legacy->handle))];
            if ($legacy->index !== null) {
                $newArgs[] = new Arg(new Int_($legacy->index));
            }
        }

        $file = new MethodCall($node->var, new Identifier('file'), $newArgs);

        return $this->isName($node->name, 'hasFile')
            ? new NotIdentical($file, new ConstFetch(new Name('null')))
            : $file;
    }
}
