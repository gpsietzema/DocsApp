<?php

namespace MODXDocs\Services;

use MODXDocs\Model\PageRequest;
use Monolog\Logger;
use Slim\Router;
use Slim\Views\Twig;
use Spatie\YamlFrontMatter\YamlFrontMatter;

use MODXDocs\Navigation\NavigationItem;
use MODXDocs\Navigation\NavigationItemBuilder;

class NavigationService
{
    private $twig;
    private $logger;
    private $router;
    private $filePathService;

    public function __construct(
        Twig $twig,
        Logger $logger,
        Router $router,
        FilePathService $filePathService
    )
    {
        $this->twig = $twig;
        $this->logger = $logger;
        $this->router = $router;
        $this->filePathService = $filePathService;
    }

    public function getTopNavigation(PageRequest $request)
    {
        return $this->getNavigationForParent(
            (new NavigationItemBuilder())
                ->forTopMenu()
                ->withCurrentFilePath($this->filePathService->getFilePath($request))
                ->withBasePath($this->filePathService->getAbsoluteContextPath($request))
                ->withFilePath($this->filePathService->getAbsoluteContextPath($request))
                ->withUrlPath($request->getContextUrl())
                ->withVersion($request->getVersion())
                ->withLanguage($request->getLanguage())
                ->build()
        );
    }

    public function getTopNavigationForItem(NavigationItem $navigationItem)
    {
        return $this->getNavigationForParent($navigationItem);
    }

    public function getNavigation(PageRequest $request)
    {
        $baseNavigationItem = (new NavigationItemBuilder())
            ->withCurrentFilePath($this->filePathService->getFilePath($request))
            ->withVersion($request->getVersion())
            ->withLanguage($request->getLanguage())
            ->build();

        // Make the navigation dependent on the current parent (administration, developing, xpdo, etc)
        $docPath = array_filter(explode('/', $request->getPath()));

        // If the docpath is empty, we are on the frontpage
        //if (count($docPath) === 1 && $docPath[0] === 'index') {
            return $this->renderNav(
                $this->getNavigationForParent(
                    NavigationItemBuilder::copyFromItem($baseNavigationItem)
                        ->withBasePath($this->filePathService->getAbsoluteContextPath($request))
                        ->withFilePath($this->filePathService->getAbsoluteContextPath($request))
                        ->withUrlPath($request->getContextUrl() . $request->getPath())

                        ->withLevel(NavigationItem::HOME_MENU_LEVEL)
                        //->withDepth(NavigationItem::HOME_MENU_DEPTH)
                        ->build()
                )
            );
        //}

        /*
        $menuFilePath = $this->filePathService->getAbsoluteContextPath($request) . $docPath[0] . '/';

        if (file_exists($menuFilePath) && is_dir($menuFilePath)) {
            return $this->renderNav(
                $this->getNavigationForParent(
                    NavigationItemBuilder::copyFromItem($baseNavigationItem)
                        ->withBasePath($this->filePathService->getAbsoluteContextPath($request))
                        ->withFilePath($menuFilePath)
                        ->withUrlPath($request->getContextUrl() . $request->getPath())
                        ->build()
                )
            );
        }
        */

        // This should not happen
        return null;
    }

    public function getNavParent(PageRequest $request)
    {
        $navigationItem = (new NavigationItemBuilder())
            ->withCurrentFilePath($this->filePathService->getFilePath($request))
            ->withFilePath($this->filePathService->getFilePath($request))
            ->withPath($request->getPath())
            ->withVersion($request->getVersion())
            ->withLanguage($request->getLanguage())
            ->build();

        // Make the navigation dependent on the current parent (administration, developing, xpdo, etc)
        $docPath = array_filter(explode('/', $navigationItem->getPath()));

        // No top parent for the front page
        if (count($docPath) === 0) {
            return null;
        }

        $menuFilePath = $navigationItem->getFilePath() . $docPath[0];

        if (file_exists($menuFilePath) && is_dir($menuFilePath)) {
            if (file_exists($menuFilePath . '.md')) {
                return $this->getNavItem(
                    $navigationItem,
                    new \SplFileInfo($menuFilePath . '.md'),
                    $docPath[0]
                );
            }

            return $this->getNavItem(
                $navigationItem,
                new \SplFileInfo($menuFilePath . '/index.md'),
                $docPath[0]
            );
        }

        return null;
    }

    private function getNavigationForParent(NavigationItem $navigationItem)
    {
        $nav = [];

        try {
            $dir = new \DirectoryIterator($navigationItem->getFilePath());
        } catch (\Exception $e) {
            $this->logger->addError(
                'Exception '
                . get_class($e)
                . ' fetching navigation for '
                . $navigationItem->getFilePath()
                . ': '
                . $e->getMessage()
            );

            return $nav;
        }

        foreach ($dir as $file) {
            if ($file->isDot()) {
                continue;
            }

            $filePath = $file->getPathname();
            $filePath = strpos($filePath, '.md') !== false ? substr($filePath, 0, strpos($filePath, '.md')) : $filePath;

            $relativeFilePath = str_replace($navigationItem->getBasePath(), '', $filePath);

            if ($file->isFile() && $file->getExtension() === 'md') {
                if ($file->getFilename() === 'index.md') {
                    continue;
                }

                $current = $this->getNavItem(
                    $navigationItem,
                    $file,
                    $relativeFilePath
                );
                $current['level'] = $navigationItem->getLevel();

                if ($navigationItem->getLevel() < $navigationItem->getDepth() && is_dir($filePath . '/')) {
                    $current['classes'] .= ' c-nav__item--has-children';
                    $current['children'] = $this->getNavigationForParent(
                        NavigationItemBuilder::copyFromItem($navigationItem)
                            ->withFilePath($filePath . '/')
                            ->withLevel($navigationItem->getLevel() + 1)
                            ->build()
                    );
                }
                $nav[] = $current;
            } elseif ($file->isDir()) {
                if (file_exists($file->getPathname() . '/index.md')) {
                    $current = $this->getNavItem(
                        $navigationItem,
                        new \SplFileInfo($file->getPathname() . '/index.md'),
                        $relativeFilePath
                    );
                    $current['level'] = $navigationItem->getLevel();
                    $current['classes'] .= ' c-nav__item--has-children';

                    if ($navigationItem->getLevel() < $navigationItem->getDepth()) {
                        $current['children'] = $this->getNavigationForParent(
                            NavigationItemBuilder::copyFromItem($navigationItem)
                                ->withFilePath($file->getPathname())
                                ->withLevel($navigationItem->getLevel() + 1)
                                ->build()
                        );
                    }

                    $nav[] = $current;
                }
            }

        }

        usort($nav, function ($item, $item2) {
            $so1 = array_key_exists('sortorder', $item) ? (int)$item['sortorder'] : null;
            $so2 = array_key_exists('sortorder', $item2) ? (int)$item2['sortorder'] : null;

            if ($so1 && !$so2) {
                return -1;
            }

            if (!$so1 && $so2) {
                return 1;
            }

            if (!$so1 && !$so2) {
                return strnatcmp($item['title'], $item2['title']);
            }

            return $so1 - $so2;
        });

        return $nav;
    }

    private function getNavItem(NavigationItem $navigationItem, \SplFileInfo $file, $relativeFilePath)
    {
        $fm = static::getNavFrontmatter($file);
        $current = [
            'title' => static::getNavTitle($file),
            'uri' => $this->router->pathFor('documentation', [
                'version' => $navigationItem->getVersion(),
                'language' => $navigationItem->getLanguage(),
                'path' => $relativeFilePath,
            ]),
            'classes' => 'c-nav__item',
        ];

        if (array_key_exists('sortorder', $fm)) {
            $current['sortorder'] = (int)$fm['sortorder'];
        }

        if (strpos($navigationItem->getCurrentFilePath(), $relativeFilePath) !== false) {
            $current['classes'] .= ' c-nav__item--active';
        }

        $path = $navigationItem->getCurrentFilePath();
        $opts = [$relativeFilePath . '.md', $relativeFilePath . '/index.md'];
        foreach ($opts as $opt) {
            if (strpos($path, $opt) !== false) {
                $current['classes'] .= ' c-nav__item--activepage';
            }
        }

        return $current;
    }

    private function renderNav(array $nav)
    {
        return $this->twig->fetch(
            'partials/nav.twig', [
                'children' => $nav
            ]
        );
    }

    private static function getNavTitle(\SplFileInfo $file)
    {
        $fm = static::getNavFrontmatter($file);
        $title = array_key_exists('title', $fm) ? $fm['title'] : '';

        if (empty($title)) {
            $name = $file->getFilename();
            $title = strpos($name, '.md') !== false ? substr($name, 0, strpos($name, '.md')) : $name;
            $title = implode(' ', explode('-', $title));
        }

        return $title;
    }

    private static function getNavFrontmatter(\SplFileInfo $file)
    {
        $fileContents = $file->isFile() ? file_get_contents($file->getPathname()) : false;
        $obj = YamlFrontMatter::parse($fileContents);
        return $obj->matter();
    }
}