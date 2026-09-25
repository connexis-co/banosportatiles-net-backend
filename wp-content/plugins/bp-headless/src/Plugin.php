<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless;

use BanosPortatiles\Headless\Admin\AdminCleanup;
use BanosPortatiles\Headless\Admin\LeadAdmin;
use BanosPortatiles\Headless\Admin\PageColumns;
use BanosPortatiles\Headless\Cache\CacheInvalidator;
use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Cli\BpCommand;
use BanosPortatiles\Headless\Cli\ReviewsCommand;
use BanosPortatiles\Headless\Cli\SeoCommand;
use BanosPortatiles\Headless\Content\PageTemplates;
use BanosPortatiles\Headless\Content\PostTypes;
use BanosPortatiles\Headless\Content\Taxonomies;
use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Deploy\DailyRebuild;
use BanosPortatiles\Headless\Deploy\DeployAdmin;
use BanosPortatiles\Headless\Deploy\DeployHook;
use BanosPortatiles\Headless\Deploy\DeployScheduler;
use BanosPortatiles\Headless\Fields\AcfFieldReader;
use BanosPortatiles\Headless\Fields\FieldRegistrar;
use BanosPortatiles\Headless\Headless\FrontendRedirect;
use BanosPortatiles\Headless\Headless\NoIndex;
use BanosPortatiles\Headless\Headless\PreviewLinks;
use BanosPortatiles\Headless\Html\ContentRenderer;
use BanosPortatiles\Headless\Html\HtmlCleaner;
use BanosPortatiles\Headless\Import\SeedImporter;
use BanosPortatiles\Headless\Leads\LeadNotifier;
use BanosPortatiles\Headless\Leads\LeadRepository;
use BanosPortatiles\Headless\Leads\LeadValidator;
use BanosPortatiles\Headless\Leads\LeadWebhook;
use BanosPortatiles\Headless\Leads\QuoteCtaAdmin;
use BanosPortatiles\Headless\Leads\RateLimiter;
use BanosPortatiles\Headless\Mail\SmtpMailer;
use BanosPortatiles\Headless\Normalizer\CiudadNormalizer;
use BanosPortatiles\Headless\Normalizer\FaqNormalizer;
use BanosPortatiles\Headless\Normalizer\HeroNormalizer;
use BanosPortatiles\Headless\Normalizer\LogoNormalizer;
use BanosPortatiles\Headless\Normalizer\NodeNormalizer;
use BanosPortatiles\Headless\Normalizer\SectionsNormalizer;
use BanosPortatiles\Headless\Normalizer\SeoNormalizer;
use BanosPortatiles\Headless\Normalizer\SiteNormalizer;
use BanosPortatiles\Headless\Normalizer\WpReferenceResolver;
use BanosPortatiles\Headless\Redirects\RedirectionRepository;
use BanosPortatiles\Headless\Rest\Controllers\ContentController;
use BanosPortatiles\Headless\Rest\Controllers\FaqsController;
use BanosPortatiles\Headless\Rest\Controllers\LeadsController;
use BanosPortatiles\Headless\Rest\Controllers\NodeController;
use BanosPortatiles\Headless\Rest\Controllers\RatingsController;
use BanosPortatiles\Headless\Rest\Controllers\RedirectsController;
use BanosPortatiles\Headless\Rest\Controllers\ReviewsController;
use BanosPortatiles\Headless\Rest\Controllers\RoutesController;
use BanosPortatiles\Headless\Rest\Controllers\SiteController;
use BanosPortatiles\Headless\Rest\Cors;
use BanosPortatiles\Headless\Rest\HttpCache;
use BanosPortatiles\Headless\Rest\RestApi;
use BanosPortatiles\Headless\Reviews\NullReviewsGateway;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Reviews\ReviewChangeListener;
use BanosPortatiles\Headless\Reviews\ReviewInputValidator;
use BanosPortatiles\Headless\Reviews\ReviewsAdmin;
use BanosPortatiles\Headless\Reviews\ReviewsWriteGuard;
use BanosPortatiles\Headless\Reviews\SiteReviewsGateway;
use BanosPortatiles\Headless\Routing\UriResolver;
use BanosPortatiles\Headless\Routing\WpNodeLocator;
use BanosPortatiles\Headless\Security\CommentsOff;
use BanosPortatiles\Headless\Security\Hardening;
use BanosPortatiles\Headless\Security\PreviewToken;
use BanosPortatiles\Headless\Security\RestGuard;
use BanosPortatiles\Headless\Security\SecurityHeaders;
use BanosPortatiles\Headless\Security\SignedRequestGuard;
use BanosPortatiles\Headless\Seo\RankMathSeoSource;
use BanosPortatiles\Headless\Seo\RankMathSeoWriter;
use BanosPortatiles\Headless\Seo\ScfSeoFields;
use BanosPortatiles\Headless\Seo\ScfSeoSource;
use BanosPortatiles\Headless\Seo\SeoContext;
use BanosPortatiles\Headless\Seo\SiteSeo;
use BanosPortatiles\Headless\Seo\WpRankMathApi;
use BanosPortatiles\Headless\Svg\SvgSanitizer;

/**
 * Composition root: builds the object graph once and registers every module's hooks.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $config = new Config;
        $uris = new UriResolver;
        $cache = new ResponseCache;
        $cmsOrigin = Config::originOf((string) home_url('/')) ?? (string) home_url();
        $frontHost = HtmlCleaner::authority($config->frontendUrl());
        $canonicalHost = HtmlCleaner::authority(Config::DEFAULT_FRONTEND_URL);
        $cleaner = new HtmlCleaner(
            [$frontHost, 'www.'.$frontHost, $canonicalHost, 'www.'.$canonicalHost],
            $cmsOrigin,
            static function (string $path) use ($uris, $cmsOrigin): ?string {
                $postId = url_to_postid($cmsOrigin.$path);

                return $postId > 0 ? $uris->forPost($postId) : null;
            }
        );
        $renderer = new ContentRenderer($cleaner);
        $refs = new WpReferenceResolver($uris, $renderer);
        $fields = new AcfFieldReader;
        $ciudades = new CiudadNormalizer($fields);
        $rankMath = new WpRankMathApi;
        $seoContext = new SeoContext($config->frontendUrl(), array_values(array_unique(array_filter([
            HtmlCleaner::authority($cmsOrigin), $frontHost, 'www.'.$frontHost, $canonicalHost, 'www.'.$canonicalHost,
        ]))));
        $seo = new SeoNormalizer(new RankMathSeoSource($rankMath, $refs, $seoContext), new ScfSeoSource($fields, $refs, $seoContext));
        $reviewsGuard = new ReviewsWriteGuard;
        $ratings = new RatingService(
            SiteReviewsGateway::isActive() ? new SiteReviewsGateway($reviewsGuard) : new NullReviewsGateway,
            $fields,
        );
        $nodeLocator = new WpNodeLocator($uris);
        $limiter = new RateLimiter;
        $signed = new SignedRequestGuard($config, $limiter);
        $reviewInput = new ReviewInputValidator;
        $nodes = new NodeNormalizer(
            $fields,
            $refs,
            $uris,
            $renderer,
            $seo,
            new HeroNormalizer($refs),
            new SectionsNormalizer($refs),
            new FaqNormalizer($refs, $renderer->fragment(...)),
            $ciudades,
            $ratings,
        );
        $tokens = new PreviewToken($config->previewSecret());
        $previews = new PreviewLinks($config, $uris, $tokens);
        $redirects = new RedirectionRepository($config);
        $listener = new ContentChangeListener;
        $deployHook = new DeployHook($config);
        $scheduler = new DeployScheduler($config, $deployHook);
        $webhook = new LeadWebhook($config);
        $leads = new LeadRepository;
        $notifier = new LeadNotifier($config);

        /** @var list<Hookable> $modules */
        $modules = [
            new PostTypes,
            new Taxonomies,
            new PageTemplates,
            new FieldRegistrar($config),
            new RestApi(
                new SiteController($cache, new SiteNormalizer($fields, $refs, $uris, $ciudades, $ratings, new SiteSeo($rankMath, $refs), new LogoNormalizer($refs, new SvgSanitizer))),
                new RoutesController($cache, $uris, $seo),
                new ContentController($cache, $nodes),
                new NodeController($cache, $nodes, $uris, $tokens),
                new FaqsController($cache, $renderer),
                new RedirectsController($cache, $redirects),
                new LeadsController($signed, new LeadValidator, $leads, $notifier, $webhook, $limiter),
                new RatingsController($cache, $ratings, $nodeLocator, $signed, $reviewInput, $limiter),
                new ReviewsController($cache, $ratings, $nodeLocator, $signed, $reviewInput, $limiter),
            ),
            new HttpCache,
            new Cors($config),
            $listener,
            new CacheInvalidator($cache),
            $scheduler,
            new DailyRebuild($config, $deployHook),
            new DeployAdmin($config, $scheduler),
            $webhook,
            $previews,
            new FrontendRedirect($config, $uris, $previews),
            new NoIndex,
            new Hardening,
            new CommentsOff,
            new RestGuard,
            new SecurityHeaders,
            new PageColumns($config, $uris),
            new LeadAdmin,
            new QuoteCtaAdmin,
            new AdminCleanup($config),
            new SmtpMailer($config),
            $reviewsGuard,
            new ReviewChangeListener($cache, $scheduler),
            new ReviewsAdmin($ratings),
            new ScfSeoFields($rankMath),
        ];

        foreach ($modules as $module) {
            $module->register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            BpCommand::register(new BpCommand(new SeedImporter($uris, $redirects, new RankMathSeoWriter($rankMath)), $scheduler, $cache, $listener, $previews, $leads, $notifier));
            ReviewsCommand::register(new ReviewsCommand($ratings, $nodeLocator, $cache));
            SeoCommand::register(new SeoCommand($fields, $uris, $cache));
        }
    }

    public static function activate(): void
    {
        (new PostTypes)->registerPostTypes();
        (new Taxonomies)->registerTaxonomies();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(DeployScheduler::HOOK);
        DailyRebuild::unschedule();
        flush_rewrite_rules();
    }
}
