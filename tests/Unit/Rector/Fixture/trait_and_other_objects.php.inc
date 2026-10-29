<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Illuminate\Database\Eloquent\Model;

class LegacyPost extends Model
{
    use \Folklore\Mediatheque\Support\Traits\HasMedias;

    public function unrelated(\ArrayObject $object): mixed
    {
        // Not a media: left as it is
        return $object->getFile('thumbnails.0');
    }
}

?>
-----
<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Illuminate\Database\Eloquent\Model;

class LegacyPost extends Model
{
    use \Folklore\Mediatheque\Concerns\HasMedias;

    public function unrelated(\ArrayObject $object): mixed
    {
        // Not a media: left as it is
        return $object->getFile('thumbnails.0');
    }
}

?>
