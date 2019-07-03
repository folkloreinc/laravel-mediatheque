<?php

namespace Folklore\Mediatheque\Console\Commands;

use Illuminate\Console\Command;

use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class ConvertVideos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mediatheque:convert_videos {--id=*} {--width=-1} {--height=-1} {--quality=20} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert videos';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $force = $this->option('force');
        $quality = $this->option('quality');
        $width = $this->option('width');
        $height = $this->option('height');

        $query = app(VideoContract::class)->newQuery();

        if ($this->option('id')) {
            $query->whereIn('id', $this->option('id'));
        }

        $videos = $query->get();

        $tmpFolder = storage_path('mediatheque/tmp');
        $convertedFolder = storage_path('mediatheque/converted');
        if (!file_exists($tmpFolder)) {
            mkdir($tmpFolder, 0777, true);
        }
        if (!file_exists($convertedFolder)) {
            mkdir($convertedFolder, 0777, true);
        }

        $format = new X264();

        $ffmpeg = FFMpeg::create();

        foreach ($videos as $video) {
            $mp4 = $video->files->mp4;
            if ($mp4 && !$force) {
                $this->line('<comment>Skiping</comment> Video #'.$video->id.'...');
                continue;
            }

            $file = $video->getOriginalFile();

            if ($file) {
                if (!$force &&
                    $file->mime === 'video/mp4' &&
                    $width === '-1' && $height === '-1' &&
                    ($video->height === 720 || $video->height === 740 || $video->width === 608)
                ) {
                    $this->line('<comment>Skip</comment> Video #'.$video->id.' Size: '.$video->width.'x'.$video->height.' ...');
                    continue;
                }

                $this->line('<comment>Converting</comment> Video #'.$video->id.' '.$file->name.' ...');

                try {
                    $pathParts = pathinfo($file->name);
                    $tmpPath = $tmpFolder.'/'.$pathParts['basename'];
                    $tmpMp4 = $convertedFolder.'/'.$pathParts['filename'].'.mp4';

                    if (file_exists($tmpPath)) {
                        unlink($tmpPath);
                    }

                    if (file_exists($tmpMp4)) {
                        unlink($tmpMp4);
                    }

                    $file->downloadFile($tmpPath);

                    if ($width === '-1' && $height === '-1') {
                        $size = '-1:'.($video->width > $video->height ? 720 : 640);
                    } else {
                        $size = $width.':'.$height;
                    }
                    $format->setAdditionalParameters([
                        '-y',
                        '-vf',
                        'scale='.$size,
                        '-preset',
                        'slower',
                        '-pix_fmt',
                        'yuv420p',
                        '-crf',
                        $quality,
                        '-movflags',
                        '+faststart'
                    ]);

                    $ffmpegVideo = $ffmpeg->open($tmpPath);
                    $ffmpegVideo->save($format, $tmpMp4);


                    if (!$mp4) {
                        $mp4 = app(FileContract::class);
                        $video->files()->save($mp4, [
                            'handle' => 'mp4',
                        ]);
                    }
                    $mp4->handle = 'mp4';
                    $mp4->setFile($tmpMp4);
                    $mp4->save();

                    $this->line('<info>Converted</info> Video #'.$video->id.' '.$file->name.' .');
                } catch (\Exception $e) {
                    $this->line('<error>Erreur</error> '.$e->getMessage().' Size: '.$video->width.'x'.$video->height);
                }

                if (file_exists($tmpPath)) {
                    unlink($tmpPath);
                }

                if (file_exists($tmpMp4)) {
                    unlink($tmpMp4);
                }
            }
        }
    }
}
