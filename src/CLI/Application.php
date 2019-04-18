<?php

namespace MODXDocs\CLI;


use MODXDocs\CLI\Commands\SourcesInit;
use MODXDocs\CLI\Commands\SourcesUpdate;
use MODXDocs\DocsApp;

class Application extends \Symfony\Component\Console\Application {

    protected $app;
    protected $container;

    public function __construct(DocsApp $docsApp)
    {
        parent::__construct('modxdocs', '1.0.0');
        $this->app = $docsApp;
        $this->container = $docsApp->getContainer();
    }

    /**
     * @return DocsApp
     */
    public function getDocsApp(): DocsApp
    {
        return $this->app;
    }

    /**
     * @return \Psr\Container\ContainerInterface
     */
    public function getContainer(): \Psr\Container\ContainerInterface
    {
        return $this->container;
    }

    protected function getDefaultCommands()
    {
        $cmds = parent::getDefaultCommands();
        $cmds[] = new SourcesInit();
        $cmds[] = new SourcesUpdate();
        return $cmds;
    }
}