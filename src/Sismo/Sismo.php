<?php

/*
 * This file is part of the Sismo utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sismo;

use Sismo\Storage\StorageInterface;

/**
 * Main entry point for Sismo.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Sismo
{
    const VERSION = '1.0.0';

    const FORCE_BUILD  = 1;
    const LOCAL_BUILD  = 2;
    const SILENT_BUILD = 4;

    private $storage;
    private $projects = array();

    /**
     * Constructor.
     *
     * @param StorageInterface $storage A StorageInterface instance
     */
    public function __construct(StorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Builds a project.
     *
     * @param Project $project  A Project instance
     * @param string  $revision The revision to build (or null for the latest revision)
     * @param integer $flags    Flags (a combinaison of FORCE_BUILD, LOCAL_BUILD, and SILENT_BUILD)
     * @param mixed   $callback A PHP callback
     */
    public function build(Project $project, $revision = null, $flags = 0, $callback = null)
    {
        $event = new BuildEvent($project, $revision, $flags, $callback);
        $this->dispatcher->dispatch(BuildEvents::PRE_BUILD, $event);
        if ($event->isPropagationStopped()) {
            return;
        }
        if ($callback) {
            call_user_func($event->getCallback(), 'out', 'BUILD START');
        }
        $this->dispatcher->dispatch(BuildEvents::BUILD, $event);
        $this->dispatcher->dispatch(BuildEvents::POST_BUILD, $event);
    }

    /**
     * Checks if Sismo knows about a given project.
     *
     * @param string $slug A project slug
     */
    public function hasProject($slug)
    {
        return isset($this->projects[$slug]);
    }

    /**
     * Gets a project.
     *
     * @param string $slug A project slug
     */
    public function getProject($slug)
    {
        if (!isset($this->projects[$slug])) {
            throw new \InvalidArgumentException(sprintf('Project "%s" does not exist.', $slug));
        }

        return $this->projects[$slug];
    }

    /**
     * Adds a project.
     *
     * @param Project $project A Project instance
     */
    public function addProject(Project $project)
    {
        $this->storage->updateProject($project);

        $this->projects[$project->getSlug()] = $project;
    }

    /**
     * Gets all projects.
     *
     * @return array An array of Project instances
     */
    public function getProjects()
    {
        return $this->projects;
    }
}
