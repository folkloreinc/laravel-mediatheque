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
     * Get a model instance from type
     *
     * @param string $path The path to the media file
     * @return \Folklore\Mediatheque\Mediatheque The mediatheque instance
     */
    function media($path = null)
    {
        if (is_null($path)) {
            return app('mediatheque');
        }
        return app('mediatheque')
            ->typeFromPath($path)
            ->newModel()
            ->setOriginalFile($path);
    }
}
