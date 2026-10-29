<?php

namespace Folklore\Mediatheque\Rector;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;

/**
 * The 1.x methods of the models that 2.0 renamed, with the same arguments
 * and result (docs/upgrade.md). Rector's RenameMethodRector leaves the
 * calls on a class that has both methods, as the models of the
 * compatibility layer do: this rule renames them.
 */
final class RenameV1MethodsRector extends AbstractRector
{
    /**
     * @var array<class-string, array<string, string>>
     */
    private const array METHODS = [
        Media::class => [
            'getOriginalFile' => 'original',
            'getUrl' => 'url',
        ],
        File::class => [
            'getUrl' => 'url',
        ],
    ];

    public function getNodeTypes(): array
    {
        return [MethodCall::class, NullsafeMethodCall::class];
    }

    /**
     * @param  MethodCall|NullsafeMethodCall  $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->getArgs() !== [] || $node->isFirstClassCallable()) {
            return null;
        }

        foreach (self::METHODS as $class => $methods) {
            foreach ($methods as $old => $new) {
                if ($this->isName($node->name, $old) && $this->isObjectType($node->var, new ObjectType($class))) {
                    $node->name = new Identifier($new);

                    return $node;
                }
            }
        }

        return null;
    }
}
