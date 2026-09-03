<?php

declare(strict_types=1);

/**
 * Builds docs/index.html (English) and docs/pl/index.html (Polish) from
 * README.md / README.pl.md.
 *
 * This is a one-off maintainer tool, not part of the package: run it by
 * hand (`composer docs`) after editing either README, then commit the
 * regenerated HTML alongside it. GitHub Pages serves the committed files
 * as-is - there is no build step on the Pages side.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

require __DIR__.'/../vendor/autoload.php';

const ROOT = __DIR__.'/..';

/** @var list<array{source: string, output: string, lang: string, title: string, description: string, switchHref: string, switchLabel: string}> */
$pages = [
    [
        'source' => ROOT.'/README.md',
        'output' => ROOT.'/docs/index.html',
        'lang' => 'en',
        'title' => 'Modsx — convention-based modules for Laravel',
        'description' => 'Organise a Laravel application into modules using nothing but a directory-naming convention.',
        'switchHref' => 'pl/',
        'switchLabel' => 'Polski',
    ],
    [
        'source' => ROOT.'/README.pl.md',
        'output' => ROOT.'/docs/pl/index.html',
        'lang' => 'pl',
        'title' => 'Modsx — moduły dla Laravela oparte na konwencji',
        'description' => 'Podziel aplikację Laravela na moduły przy pomocy samej konwencji nazewnictwa katalogów.',
        'switchHref' => '../',
        'switchLabel' => 'English',
    ],
];

$converter = buildConverter();

foreach ($pages as $page) {
    $markdown = file_get_contents($page['source']);

    if ($markdown === false) {
        fwrite(STDERR, "Could not read {$page['source']}\n");

        exit(1);
    }

    $content = (string) $converter->convert($markdown);

    // The READMEs link to each other, and to a few repo files, by relative
    // path - meaningful on GitHub, meaningless on the built site since none
    // of those files exist under docs/. Point them at the actual GitHub
    // destinations instead.
    $content = str_replace(
        ['href="README.md"', 'href="README.pl.md"'],
        'href="'.$page['switchHref'].'"',
        $content
    );
    $content = str_replace(
        ['href="CONTRIBUTING.md"', 'href="LICENSE"'],
        [
            'href="https://github.com/Synerdy/modsx/blob/master/CONTRIBUTING.md"',
            'href="https://github.com/Synerdy/modsx/blob/master/LICENSE"',
        ],
        $content
    );

    $nav = buildNav($content);
    $html = renderPage($page, $content, $nav);

    @mkdir(dirname($page['output']), 0777, true);
    file_put_contents($page['output'], $html);

    echo "Wrote {$page['output']}\n";
}

file_put_contents(ROOT.'/docs/.nojekyll', '');
echo 'Wrote '.ROOT."/docs/.nojekyll\n";

function buildConverter(): MarkdownConverter
{
    $environment = new Environment([
        'heading_permalink' => [
            'min_heading_level' => 2,
            // Down to h4, one level deeper than the sidebar shows. The
            // navigation stays two levels for legibility, but a subsection
            // still needs an anchor: without one, a link to "Trying the 1.0
            // beta" works on GitHub, where anchors are generated, and lands
            // at the top of the page here.
            'max_heading_level' => 4,
            'insert' => 'none',
            'id_prefix' => '',
            'apply_id_to_heading' => true,
        ],
    ]);

    $environment->addExtension(new CommonMarkCoreExtension);
    $environment->addExtension(new GithubFlavoredMarkdownExtension);
    $environment->addExtension(new HeadingPermalinkExtension);

    return new MarkdownConverter($environment);
}

/**
 * Nested <nav> markup built from the rendered content's own <h2>/<h3>
 * elements - every <h3> nests under the nearest preceding <h2>, mirroring
 * document order exactly, so the sidebar always matches the page.
 */
function buildNav(string $html): string
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');
    libxml_use_internal_errors(false);

    $xpath = new DOMXPath($dom);
    $headings = $xpath->query('//h2 | //h3');

    if ($headings === false || $headings->length === 0) {
        return '';
    }

    $nav = '<nav class="toc" aria-label="Table of contents"><ul>';
    $openTopLevelItem = false;
    $openSubmenu = false;

    foreach ($headings as $heading) {
        /** @var DOMElement $heading */
        $id = $heading->getAttribute('id');
        $text = trim($heading->textContent);

        if ($heading->tagName === 'h2') {
            if ($openSubmenu) {
                $nav .= '</ul>';
                $openSubmenu = false;
            }

            if ($openTopLevelItem) {
                $nav .= '</li>';
            }

            $nav .= sprintf(
                '<li><a href="#%s">%s</a>',
                htmlspecialchars($id, ENT_QUOTES),
                htmlspecialchars($text, ENT_QUOTES)
            );
            $openTopLevelItem = true;
        } else {
            if (! $openSubmenu) {
                $nav .= '<ul class="toc-sub">';
                $openSubmenu = true;
            }

            $nav .= sprintf(
                '<li><a href="#%s">%s</a></li>',
                htmlspecialchars($id, ENT_QUOTES),
                htmlspecialchars($text, ENT_QUOTES)
            );
        }
    }

    if ($openSubmenu) {
        $nav .= '</ul>';
    }

    if ($openTopLevelItem) {
        $nav .= '</li>';
    }

    $nav .= '</ul></nav>';

    return $nav;
}

/**
 * @param  array{source: string, output: string, lang: string, title: string, description: string, switchHref: string, switchLabel: string}  $page
 */
function renderPage(array $page, string $content, string $nav): string
{
    $lang = htmlspecialchars($page['lang'], ENT_QUOTES);
    $title = htmlspecialchars($page['title'], ENT_QUOTES);
    $description = htmlspecialchars($page['description'], ENT_QUOTES);
    $switchHref = htmlspecialchars($page['switchHref'], ENT_QUOTES);
    $switchLabel = htmlspecialchars($page['switchLabel'], ENT_QUOTES);
    $style = styles();
    $script = script();

    return <<<HTML
        <!doctype html>
        <html lang="{$lang}">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <meta name="description" content="{$description}">
        <style>{$style}</style>
        </head>
        <body>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle navigation">☰</button>
        <div class="layout">
        <aside id="sidebar" class="sidebar">
        <div class="sidebar-head">
        <span class="brand">Modsx</span>
        <a class="lang-switch" href="{$switchHref}">{$switchLabel}</a>
        </div>
        {$nav}
        <a class="github-link" href="https://github.com/Synerdy/modsx">GitHub ↗</a>
        </aside>
        <main class="content">
        {$content}
        </main>
        </div>
        <script>{$script}</script>
        </body>
        </html>
        HTML;
}

function styles(): string
{
    return <<<'CSS'
        :root {
            --bg: #ffffff;
            --bg-alt: #f6f8fa;
            --text: #1b1f24;
            --text-muted: #57606a;
            --border: #d0d7de;
            --link: #0969da;
            --code-bg: #f6f8fa;
            --sidebar-width: 280px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --bg-alt: #161b22;
                --text: #e6edf3;
                --text-muted: #8d96a0;
                --border: #30363d;
                --link: #4493f8;
                --code-bg: #161b22;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }
        a { color: var(--link); }
        .layout {
            display: flex;
            max-width: 1200px;
            margin: 0 auto;
        }
        .sidebar {
            width: var(--sidebar-width);
            flex-shrink: 0;
            border-right: 1px solid var(--border);
            padding: 1.5rem 1rem;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow-y: auto;
        }
        .sidebar-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .brand { font-weight: 700; font-size: 1.1rem; }
        .lang-switch { font-size: 0.85rem; text-decoration: none; }
        .toc, .toc ul { list-style: none; margin: 0; padding: 0; }
        .toc > ul { padding: 0; }
        .toc a {
            display: block;
            padding: 0.3rem 0.5rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text);
            font-size: 0.92rem;
        }
        .toc > li > a { font-weight: 600; }
        .toc-sub a { padding-left: 1.25rem; font-weight: 400; color: var(--text-muted); }
        .toc a:hover, .toc a.active { background: var(--bg-alt); color: var(--link); }
        .github-link {
            display: block;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            text-decoration: none;
            font-size: 0.9rem;
        }
        .content {
            flex: 1;
            min-width: 0;
            padding: 2rem 3rem 6rem;
        }
        .content h1 { font-size: 2rem; }
        .content h2 { margin-top: 2.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border); }
        .content h3 { margin-top: 1.75rem; }
        .content pre {
            background: var(--code-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            overflow-x: auto;
        }
        .content code {
            background: var(--code-bg);
            border-radius: 4px;
            padding: 0.15em 0.4em;
            font-size: 0.9em;
        }
        .content pre code { background: none; padding: 0; }
        .content table {
            border-collapse: collapse;
            width: 100%;
            overflow-x: auto;
            display: block;
        }
        .content th, .content td {
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            text-align: left;
        }
        .content blockquote {
            margin: 1rem 0;
            padding: 0.25rem 1rem;
            border-left: 4px solid var(--border);
            color: var(--text-muted);
        }
        .content img { max-width: 100%; }
        .menu-toggle { display: none; }
        @media (max-width: 900px) {
            .menu-toggle {
                display: block;
                position: fixed;
                top: 0.75rem;
                left: 0.75rem;
                z-index: 20;
                background: var(--bg-alt);
                border: 1px solid var(--border);
                border-radius: 6px;
                font-size: 1.1rem;
                padding: 0.25rem 0.6rem;
            }
            .sidebar {
                position: fixed;
                inset: 0 25% 0 0;
                z-index: 10;
                background: var(--bg);
                transform: translateX(-100%);
                transition: transform 0.2s ease;
            }
            .sidebar.open { transform: translateX(0); }
            .content { padding: 4rem 1.25rem 4rem; }
        }
        CSS;
}

function script(): string
{
    return <<<'JS'
        (function () {
            var toggle = document.querySelector('.menu-toggle');
            var sidebar = document.getElementById('sidebar');

            toggle.addEventListener('click', function () {
                var open = sidebar.classList.toggle('open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    sidebar.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            });

            var links = Array.prototype.slice.call(sidebar.querySelectorAll('.toc a'));
            var targets = links
                .map(function (link) { return document.getElementById(link.getAttribute('href').slice(1)); })
                .filter(Boolean);

            if (! targets.length || ! ('IntersectionObserver' in window)) {
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (! entry.isIntersecting) {
                        return;
                    }

                    links.forEach(function (link) { link.classList.remove('active'); });

                    var active = links.find(function (link) {
                        return link.getAttribute('href') === '#' + entry.target.id;
                    });

                    if (active) {
                        active.classList.add('active');
                    }
                });
            }, { rootMargin: '0px 0px -70% 0px' });

            targets.forEach(function (target) { observer.observe(target); });
        })();
        JS;
}
