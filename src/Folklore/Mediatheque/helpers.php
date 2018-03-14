<?php

if (!function_exists('mediatheque')) {
    /**
     * Get the mediatheque instance
     *
     * @return \Folklore\Mediatheque\Mediatheque The mediatheque instance
     */
    function mediatheque()
    {
        return app('mediatheque');
    }
}

if (!function_exists('media')) {
    /**
     * Get the mediatheque instance
     *
     * @return \Folklore\Mediatheque\Mediatheque The mediatheque instance
     */
    function media($path = null)
    {
        if (is_null($path)) {
            return app('mediatheque');
        }
        return app('mediatheque')
            ->type(app(\Folklore\Mediatheque\Contracts\Getter\Type::class)->getType($path))
            ->model()
            ->setOriginalFile($path);
    }
}
