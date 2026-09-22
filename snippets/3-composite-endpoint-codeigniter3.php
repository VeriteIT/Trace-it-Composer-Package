<?php

/**
 * SNIPPET 3, CODEIGNITER 3 VARIANT — serve the composited image from your domain.
 * ===========================================================================
 * Use this INSTEAD OF 3-composite-endpoint.php when your site runs CodeIgniter 3.
 * Everything in that file's header still applies — why compositing is the only
 * thing that satisfies "Save image as…", why it runs on your server, and why
 * ext-gd is required. This file changes one thing: how the response is sent.
 *
 * WHY IT MUST NOT CALL send(). FramedImage::send() writes headers with header()
 * and echoes the body itself. CodeIgniter holds the whole response and sends it
 * at the end of the request, so echoing past it puts the image ahead of the
 * framework's own output, and the headers you set by hand are then overwritten
 * or sent twice. The symptom is a broken image served as text/html — which reads
 * like a compositing failure and is not one. Hand the bytes to CodeIgniter.
 *
 * WHERE THIS HAS TO LIVE: on the site the public can reach. The page script sets
 * img.src to this URL, and Facebook, X and WhatsApp fetch it directly when they
 * build a share preview. An admin-only or IP-restricted CMS therefore cannot
 * host it — every reader and every crawler would be refused, and thumbnails
 * would quietly stay uncoded with nothing appearing broken.
 *
 * INSTALL
 *   1. application/controllers/Qr_image.php   <- this file
 *   2. application/config/routes.php:
 *        $route['traceit/v1/framed/([A-Za-z0-9_-]+)\.jpg'] = 'qr_image/framed/$1';
 *   3. application/config/config.php:
 *        $config['composer_autoload'] = TRUE;
 *      (or require your vendor/autoload.php yourself, before CI boots)
 *   4. point the page script at this origin:
 *        data-service="https://www.example.lk/traceit"
 *
 * There is deliberately no declare(strict_types=1) here. CodeIgniter 3 hands the
 * controller its own loosely typed values — route captures and query parameters
 * are always strings, models return what they return — and strict mode turns
 * ordinary framework behaviour into TypeErrors.
 * ===========================================================================
 */

defined('BASEPATH') or exit('No direct script access allowed');

use VeriteIt\TraceItQr\TraceIt;
use VeriteIt\TraceItQr\TraceItException;

class Qr_image extends CI_Controller
{
    /** @var TraceIt */
    private $traceIt;

    public function __construct()
    {
        parent::__construct();

        // Your existing article lookup, under whatever name your project uses.
        $this->load->model('article_model');

        /*
         * THE SAME CONFIGURATION AS SNIPPET 1, not a second one. In a real
         * project move it into application/libraries/Traceit.php and load it
         * with $this->load->library('traceit'); it is spelled out here so this
         * file reads on its own.
         *
         * Keep cacheDir identical to the publish hook's. Two cache directories
         * means this endpoint cannot see codes the hook already fetched, and
         * every request pays a round trip to discover that.
         */
        $this->traceIt = new TraceIt([
            'apiKey'   => getenv('TRACEIT_API_KEY'),
            'baseUrl'  => getenv('TRACEIT_BASE'),
            'cacheDir' => '/var/lib/trace-it',

            /*
             * REQUIRED HERE, AND IT IS A SECURITY CONTROL.
             *
             * This endpoint fetches an image URL server-side. Without an
             * allowlist that is a Server-Side Request Forgery hole: anyone could
             * aim it at 169.254.169.254 for cloud instance credentials, at a
             * localhost admin port, or at anything else your server can reach
             * and the internet cannot.
             *
             * List the hostnames your article photos actually come from. Nothing
             * else is fetched — the check runs before any connection is made.
             */
            'allowedImageHosts' => ['cdn.example.lk'],
        ]);
    }

    public function framed($postId = '')
    {
        /*
         * Badge design version. Composites are served immutable, so a browser
         * that has one never asks again. Bump it when the badge design changes,
         * or a redesign stays invisible to everyone who already loaded the page.
         *
         * The same applies to a REPLACED PHOTO. If swapping photos after
         * publication is routine for you, put something per-article in here — a
         * photo id, or a hash of the URL — rather than one global number.
         */
        $version = (string) $this->input->get('v');
        if ($version === '') {
            $version = '1';
        }

        try {
            /*
             * Look the photo up from your own data.
             *
             * A remembered URL is only refreshed when publish() is next called
             * with a different one, so replacing an article's photo without
             * re-publishing leaves every composite carrying the old picture. A
             * lookup cannot go stale.
             *
             * IF THIS SITE CANNOT REACH THE ARTICLE DATA — a separate database,
             * a read-only front end — pass null instead:
             *
             *     $framed = $this->traceIt->framedImage($postId, null, $version);
             *
             * and give publish() the image URL as its fourth argument so there is
             * something to fall back to. Prefer the lookup where you can have it.
             */
            $article = $this->article_model->find_by_post_id($postId);
            if ($article === null) {
                return $this->degrade($postId, 'no article for that post id');
            }

            $framed = $this->traceIt->framedImage($postId, $article->thumb_url, $version);
        } catch (TraceItException $e) {
            return $this->degrade($postId, $e->getMessage());
        }

        /*
         * This is the whole point of the file. headers() returns exactly what
         * send() would have written — Content-Type, Content-Length,
         * Cache-Control: immutable, a Content-Disposition filename for the save
         * dialog, and X-Content-Type-Options — as a plain name => value array.
         * CodeIgniter then sends them in its own order, with its own buffering.
         */
        foreach ($framed->headers($postId) as $name => $value) {
            $this->output->set_header($name . ': ' . $value);
        }

        $this->output->set_output($framed->bytes);
    }

    /**
     * Degrade, do not break the page. A broken image is far worse than a photo
     * without a code: the page script handles a failed composite by leaving the
     * publisher's original photo exactly where it was.
     *
     * 404 rather than 500, because there is no composite for this request and
     * nothing a retry would fix. show_404() is deliberately not used — it renders
     * an HTML error page, and this URL is fetched as an image.
     */
    private function degrade($postId, $why)
    {
        log_message('error', '[traceit] composite failed for ' . $postId . ': ' . $why);

        $this->output->set_status_header(404);
        $this->output->set_output('');
    }
}
