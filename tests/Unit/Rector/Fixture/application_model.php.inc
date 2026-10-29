<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;

class ApplicationModel
{
    public function url(TenantMedia $media): ?string
    {
        return $media->getUrl() ?? $media->getFile('poster')?->getUrl();
    }
}

?>
-----
<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;

class ApplicationModel
{
    public function url(TenantMedia $media): ?string
    {
        return $media->url() ?? $media->file('poster')?->url();
    }
}

?>
