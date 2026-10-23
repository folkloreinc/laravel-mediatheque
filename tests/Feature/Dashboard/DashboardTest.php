<?php

namespace Folklore\Mediatheque\Tests\Feature\Dashboard;

use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\ScriptedTranscoder;
use Folklore\Mediatheque\Tests\Support\User;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class DashboardTest extends DatabaseTestCase
{
    use CreatesMedia;

    private ScriptedTranscoder $transcoder;

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // As an application does it, in its routes file: Route::mediathequeDashboard()
        $router->middleware(['web'])->group(fn () => $router->__call('mediathequeDashboard', []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->transcoder = new ScriptedTranscoder(app(Recorder::class));
        app(TranscoderManager::class)->extend('scripted', fn () => $this->transcoder);
        config(['mediatheque.pipelines.video.transcoder' => 'scripted']);
    }

    private function user(): User
    {
        $user = new User;
        $user->save();

        return $user;
    }

    private function allow(bool $view = true, bool $operate = true): void
    {
        Gate::define('viewMediathequeDashboard', fn (User $user) => $view);
        Gate::define('operateMediathequeDashboard', fn (User $user) => $operate);
    }

    private function inLocal(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app['env'] = 'local';
    }

    private function failedRun(?Media $media = null): PipelineRun
    {
        $media ??= $this->createMedia(['name' => 'Episode 1', 'status' => MediaStatus::Ready]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4']);
        $run = new PipelineRun;
        $run->forceFill([
            'name' => 'video',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'scripted',
            'definition' => ['transcoder' => 'scripted', 'renditions' => ['poster' => ['op' => 'video.poster', 'role' => 'poster']], 'main' => null],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid.'/run',
            'visibility' => Visibility::Public,
            'status' => PipelineStatus::Failed,
            'error' => ['code' => 'timeout', 'message' => 'The poster took too long.'],
            'ended_at' => now(),
        ])->save();
        (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => 'poster', 'status' => JobStatus::Failed, 'error' => ['code' => 'timeout', 'message' => 'The poster took too long.'], 'ended_at' => now()])->save();

        return $run;
    }

    #[Test]
    public function the_dashboard_is_open_in_local_until_the_application_defines_its_gates(): void
    {
        $this->get('/mediatheque')->assertForbidden();
        $this->getJson('/mediatheque/api/overview')->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');

        $this->inLocal();
        $this->get('/mediatheque')->assertOk();

        Gate::define('viewMediathequeDashboard', fn (?User $user) => false);
        $this->get('/mediatheque')->assertForbidden();
    }

    #[Test]
    public function the_gates_decide_who_views_and_who_operates(): void
    {
        $this->allow(view: true, operate: false);
        $run = $this->failedRun();

        $this->get('/mediatheque')->assertForbidden();
        $this->actingAs($this->user())->get('/mediatheque')->assertOk();
        $this->postJson('/mediatheque/api/runs/'.$run->uid.'/retry')->assertForbidden();
        $this->assertSame(1, PipelineRun::query()->count());
    }

    #[Test]
    public function the_page_loads_the_precompiled_assets_with_its_config(): void
    {
        $this->allow(operate: false);

        $response = $this->actingAs($this->user())->get('/mediatheque')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('#<script type="module" src="http://localhost/mediatheque/assets/dashboard\.js\?v=[0-9a-f]{12}"></script>#', $html);
        $this->assertStringContainsString('/mediatheque/assets/dashboard.css?v=', $html);
        $this->assertMatchesRegularExpression('#data-config=\'\{"api":"http:\\\\/\\\\/localhost\\\\/mediatheque\\\\/api","csrfToken":"[^"]+","canOperate":false\}\'#', $html);
    }

    #[Test]
    public function the_assets_are_served_from_dist(): void
    {
        $this->allow();
        $this->actingAs($this->user());

        $this->get('/mediatheque/assets/dashboard.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $this->get('/mediatheque/assets/dashboard.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/mediatheque/assets/missing.js')->assertNotFound();
        $this->get('/mediatheque/assets/..%2Fcomposer.json')->assertNotFound();
    }

    #[Test]
    public function the_api_reads_the_monitoring_read_model(): void
    {
        $this->allow();
        $run = $this->failedRun();
        $this->actingAs($this->user());

        $this->getJson('/mediatheque/api/overview?hours=24')->assertOk()
            ->assertJsonPath('data.media.ready', 1)
            ->assertJsonPath('data.runs_ended.failed', 1);
        $this->getJson('/mediatheque/api/runs?status=failed&error_code=timeout')->assertOk()
            ->assertJsonPath('data.0.id', $run->uid)
            ->assertJsonPath('data.0.renditions.0.rendition', 'poster')
            ->assertJsonPath('next_cursor', null);
        $this->getJson('/mediatheque/api/runs?status=completed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/mediatheque/api/failures')->assertOk()
            ->assertJsonPath('data.0.code', 'timeout')
            ->assertJsonPath('data.0.count', 1);
        $this->getJson('/mediatheque/api/durations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/mediatheque/api/throughput')->assertOk()->assertJsonPath('data.bytes_ingested', 3);
    }

    #[Test]
    public function a_run_says_what_the_dashboard_can_do_with_it(): void
    {
        $this->allow();
        $run = $this->failedRun();

        $this->actingAs($this->user())->getJson('/mediatheque/api/runs/'.$run->uid)->assertOk()
            ->assertJsonPath('data.id', $run->uid)
            ->assertJsonPath('data.actions', ['retry' => true, 'cancel' => false, 'refresh' => false, 'probe_again' => false]);
        $this->getJson('/mediatheque/api/runs/run_01JAB3QX5Z8Y7W6V5T4S3R2Q1P')->assertNotFound();
    }

    #[Test]
    public function runs_are_retried_canceled_and_refreshed(): void
    {
        $this->allow();
        $failed = $this->failedRun();
        $this->actingAs($this->user());

        $retry = $this->postJson('/mediatheque/api/runs/'.$failed->uid.'/retry')->assertCreated()->assertJsonPath('data.status', 'completed');
        $this->assertNotSame($failed->uid, $retry->json('data.id'));
        $this->postJson('/mediatheque/api/runs/'.$failed->uid.'/retry')->assertStatus(409)->assertJsonPath('reason', 'nothing_to_retry');

        $this->transcoder->hold = true;
        $running = Mediatheque::runPipeline($failed->media(), 'video', force: true);
        $this->assertNotNull($running);
        $this->postJson('/mediatheque/api/runs/'.$running->key().'/refresh')->assertOk();
        $this->assertSame([$running->key()], $this->transcoder->refreshed);
        $this->postJson('/mediatheque/api/runs/'.$running->key().'/cancel')->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->postJson('/mediatheque/api/runs/'.$running->key().'/cancel')->assertStatus(409)->assertJsonPath('reason', 'not_cancelable');
    }

    #[Test]
    public function a_media_whose_probe_failed_is_probed_again(): void
    {
        $this->allow();
        Bus::fake([ProbeMedia::class]);
        $failed = $this->createMedia(['status' => MediaStatus::Failed]);
        $ready = $this->createMedia(['status' => MediaStatus::Ready, 'metadata' => ['kind' => 'video']]);
        $this->actingAs($this->user());

        $this->postJson('/mediatheque/api/media/'.$failed->uid.'/probe')->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->postJson('/mediatheque/api/media/'.$ready->uid.'/probe')->assertStatus(409);
        $this->postJson('/mediatheque/api/media/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/probe')->assertNotFound();
        Bus::assertDispatchedTimes(ProbeMedia::class, 1);
    }

    #[Test]
    public function the_monitoring_scope_hides_other_tenants_runs_from_the_api_and_its_actions(): void
    {
        $this->allow();
        $theirs = $this->failedRun($this->createMedia(['name' => 'Theirs']));
        Mediatheque::monitoringScope(fn (Builder $query, string $model) => $model === 'media' ? $query->where('name', 'Mine') : $query);
        $this->actingAs($this->user());

        $this->getJson('/mediatheque/api/runs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/mediatheque/api/runs/'.$theirs->uid)->assertNotFound();
        $this->postJson('/mediatheque/api/runs/'.$theirs->uid.'/retry')->assertNotFound();
    }
}
