<?php

namespace Folklore\Mediatheque\Rector;

use Folklore\Mediatheque\Contracts\Models\Media;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

/**
 * `$media->getMetadatas()->get('width')->getValue()` and
 * `$media->getMetadata('width')->getValue()` become
 * `$media->metadata('width')` (docs/upgrade.md). `colors` is `palette` in
 * 2.0. `font_family_name` is left: `font_names` is a list, and the
 * compatibility layer still reads it. So is a key known only at runtime.
 */
final class MetadataValueRector extends AbstractRector
{
    private const array KEYS = ['colors' => 'palette'];

    public function getNodeTypes(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    /**
     * @param  MethodCall|NullsafeMethodCall  $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isName($node->name, 'getValue') || $node->getArgs() !== []) {
            return null;
        }

        [$media, $key] = $this->metadataCall($node->var) ?? [null, null];
        if ($media === null || $key === null) {
            return null;
        }

        // A key known only at runtime may be a 1.x name (`colors`), which
        // metadata() doesn't read: the compatibility layer still does
        if (! $key instanceof String_ || $key->value === 'font_family_name') {
            return null;
        }
        $key = new String_(self::KEYS[$key->value] ?? $key->value);

        return new MethodCall($media, new Identifier('metadata'), [new Arg($key)]);
    }

    /**
     * The media and the key of `$media->getMetadatas()->get($key)` or
     * `$media->getMetadata($key)`.
     *
     * @return array{0: Expr, 1: Expr}|null
     */
    private function metadataCall(Expr $call): ?array
    {
        if (! $call instanceof MethodCall || count($call->getArgs()) !== 1) {
            return null;
        }

        $key = $call->getArgs()[0]->value;

        if ($this->isName($call->name, 'getMetadata') && $this->isObjectType($call->var, new ObjectType(Media::class))) {
            return [$call->var, $key];
        }

        $metadatas = $call->var;
        if ($this->isName($call->name, 'get')
            && $metadatas instanceof MethodCall
            && $this->isName($metadatas->name, 'getMetadatas')
            && $metadatas->getArgs() === []
            && $this->isObjectType($metadatas->var, new ObjectType(Media::class))) {
            return [$metadatas->var, $key];
        }

        return null;
    }
}
