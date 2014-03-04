<?php

namespace Sismo;

use Symfony\Component\EventDispatcher\Event;

class BuildEvent extends Event
{
    private $project;
    private $revision;
    private $flags;
    private $callback;
    private $commit;

    public function __construct(Project $project, $revision = null, $flags = 0,  $callback = null)
    {
        $this->project  = $project;
        $this->revision = $revision;
        $this->flags    = $flags;
        $this->callback = $callback;
    }

    public function getProject()
    {
        return $this->project;
    }

    public function getRevision()
    {
        return $this->revision;
    }

    public function hasFlag($flag)
    {
        return $flag === $this->flags & $flag;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function setCommit(Commit $commit)
    {
        $this->commit = $commit;
    }

    public function getCommit()
    {
        return $this->commit;
    }
}
