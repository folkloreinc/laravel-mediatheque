# 0026: The `ffmpeg` driver encodes H.264 for the web, and can copy a web-ready video

- Status: proposed
- Date: 2026-10-30

## Context

The `ffmpeg` driver's `video.transcode` follows Eclair's operation ([0005](0005-asynchronous-transcoders.md)), and `laravel-eclair` translates the driver's defaults where Eclair's differ, so that a pipeline gives the same renditions on both. Its H.264 was x264's `medium` preset, High profile and CRF 23, with no maximum bitrate and x264's keyframe interval (10 seconds and more). It always encoded.

Eclair now encodes H.264 for progressive playback on the web, and copies a video that already fits when the rendition asks for it ([its decision 0019](https://github.com/urbania-media/eclair/blob/main/docs/decisions/0019-h264-for-the-web-and-passthrough.md), which has the measurements and the sources). Re-encoding an upload that is already web-ready H.264 takes minutes, can give a larger file than the upload, and can only lose quality.

## Decision

The driver takes Eclair's settings and option, in the mediatheque's names:

- **H.264's defaults** stay `medium`, High and CRF 23, and gain a keyframe every 4 seconds at most, so that a seek reads and decodes little, and a maximum bitrate from the output's size: 1000 kbit/s up to 640 × 360, 2000 up to 960 × 540, 3000 up to 1280 × 720, 6000 up to 1920 × 1080, 10 000 up to 2560 × 1440, 16 000 above, half again above 30 fps, with a buffer of twice the rate.
- **`max_bitrate`**, in kbit/s like Eclair's `maxBitrate`, replaces that default. For VP9 it is the target bitrate, and VP9 stays at constant quality without it.
- **`passthrough: true`** copies the video stream into the MP4 instead of encoding it when it is H.264 in 8-bit 4:2:0, in the Constrained Baseline, Main or High profile up to level 4.2, upright, progressive, with square pixels, within `max_width` and `max_height` and at most the maximum bitrate. The audio is copied too when it is AAC in mono or stereo at most twice `audio_bitrate`, and encoded otherwise. It is opt-in, and only applies to `codec: h264`.
- **The rendition's metadata says what happened**: `video` and `audio` are `copied` or `encoded`. An operation reports it with its `LocalOutput`, as Eclair reports it in the task's metadata, which `laravel-eclair` records.
- The operation reads its input with ffprobe for this, since the media's probe doesn't keep the profile, the level or the pixel format.

## Consequences

- Applications that want web-ready uploads copied turn `passthrough` on in their pipeline. The default pipeline keeps it off.
- A 720p or smaller video in two renditions of different maximum sizes gives the same file twice, copied or encoded. `when` has no condition on the size of the input; one can be added if an application needs it.
- `laravel-eclair` sends `passthrough` and `max_bitrate` as they are. Eclair needs the version of `video.transcode` that knows `passthrough`: an older one refuses the option.
