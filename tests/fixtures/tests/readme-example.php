<?php

namespace Tests\Feature\Http\Controllers;

use App\Post;
use App\Events\NewPost;
use App\Jobs\SyncMedia;
use App\Mail\ReviewNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * @see \App\Http\Controllers\PostController
 */
class PostControllerTest extends TestCase
{
    use WithFaker, RefreshDatabase;

    /**
     * @test
     */
    public function index_displays_view()
    {
        factory(Post::class, 3);

        $response = $this->get(route('post.index'));

        $response->assertOk();
        $response->assertViewIs('post.index');
        $response->assertViewHas('posts', Post::all());
    }

    /**
     * @test
     */
    public function store_uses_form_request_validation()
    {
        $this->assertActionUsesFormRequest(
            \App\Http\Controllers\PostController::class,
            'store',
            \App\Http\Requests\PostStoreRequest::class
        );
    }

    /**
     * @test
     */
    public function store_saves_and_redirects()
    {
        $title = $this->faker->words(3, true);
        $content = $this->faker->sentences(3, true);

        Mail::fake();
        Queue::fake();
        Event::fake();

        $response = $this->post(route('post.store'), [
            'title' => $title,
            'content' => $content,
        ]);

        $posts = Post::where('title', $title)
            ->where('content', $content)
            ->get();
        $this->assertCount(1, $posts);
        $post = $posts->first();

        $response->assertRedirect(route('post.index'));
        $response->assertSessionHas('post.index', $post->title);

        Mail::assertSent(ReviewNotification::class, function ($mailable) use ($post) {
            return $mailable->hasTo($post->author) && $mailable->post->is($post);
        });
        Queue::assertPushed(SyncMedia::class, function ($job) use ($post) {
            return $job->post->is($post);
        });
        Event::assertDispatched(NewPost::class, function ($event, $arguments) use ($post) {
            return $arguments[0]->is($post);
        });
    }
}
