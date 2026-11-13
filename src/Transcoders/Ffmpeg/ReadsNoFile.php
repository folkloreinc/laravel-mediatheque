<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

/**
 * An operation that reads no file: what it needs is in the media's metadata,
 * such as an embed's thumbnail (`embed.thumbnail`, docs/decisions/0031). A
 * rendition of it that reads from the original runs on a media whose
 * original is remote, and its context's `input` is empty.
 */
interface ReadsNoFile {}
