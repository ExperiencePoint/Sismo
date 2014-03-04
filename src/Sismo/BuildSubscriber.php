<?php

namespace Sismo;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\Process\Process;

class BuildSubscriber implements EventSubscriberInterface
{
    private $storage;
    private $baseBuildDir;

    public function __construct($storage, $baseBuildDir, $gitPath, array $gitCmds)
    {
        $this->storage      = $storage;
        $this->baseBuildDir = $baseBuildDir;
        $this->gitPath      = $gitPath;
        $this->gitCmds      = array_replace(array(
            'clone'    => 'clone --progress --recursive %repo% %dir% --branch %localbranch%',
            'fetch'    => 'fetch origin',
            'prepare'  => 'submodule update --init --recursive',
            'checkout' => 'checkout -q -f %branch%',
            'reset'    => 'reset --hard %revision%',
            'show'     => 'show -s --pretty=format:%format% %revision%',
        ), $gitCmds);
    }

    public static function getSubscribedEvents()
    {
        return [
            BuildEvents::PRE_BUILD  => [
                ['check', 100],
                ['prepare', 0],
            ],
            BuildEvents::BUILD      => [
                ['build', 0],
            ],
            BuildEvents::POST_BUILD => [
                ['notify', 0],
            ],
        ];
    }

    public function check(BuildEvent $event)
    {
        if ($event->getProject()->isBuilding() && !$event->hasFlag(Sismo::FORCE_BUILD)) {
            $event->stopPropagation();
        }
    }

    public function prepare(BuildEvent $event)
    {
        $project  = $event->getProject();
        $revision = $event->getRevision();
        $sync     = !$event->hasFlag(Sismo::LOCAL_BUILD);
        $buildDir = $this->getBuildDir($project);

        if (!file_exists($buildDir)) {
            mkdir($buildDir, 0777, true);
        }

        if (!file_exists($buildDir.'/.git')) {
            $this->execute($this->getGitCommand('clone', $project), $event, sprintf('Unable to clone repository for project "%s".', $project));
        }

        if ($sync) {
            $this->execute($this->gitPath.' '.$this->gitCmds['fetch'], $event,  sprintf('Unable to fetch repository for project "%s".', $event->getProject()));
        }

        $this->execute($this->getGitCommand('checkout', $project), $event, sprintf('Unable to checkout branch "%s" for project "%s".', $project->getBranch(), $project));

        if ($sync) {
            $this->execute($this->gitPath.' '.$this->gitCmds['prepare'], $event, sprintf('Unable to update submodules for project "%s".', $project));
        }

        if (null === $revision || 'HEAD' === $revision) {
            $revision = null;
            if (file_exists($file = $buildDir.'/.git/HEAD')) {
                $revision = trim(file_get_contents($file));
                if (0 === strpos($revision, 'ref: ')) {
                    if (file_exists($file = $buildDir.'/.git/'.substr($revision, 5))) {
                        $revision = trim(file_get_contents($file));
                    } else {
                        $revision = null;
                    }
                }
            }

            if (null === $revision) {
                throw new BuildException(sprintf('Unable to get HEAD for branch "%s" for project "%s".', $project->getBranch(), $project));
            }
        }

        $this->execute($this->getGitCommand('reset', $project, array('%revision%' => escapeshellarg($revision))), $event, sprintf('Revision "%s" for project "%s" does not exist.', $revision, $project));

        $process = $this->execute($this->getGitCommand('show', $project, array('%revision%' => escapeshellarg($revision))), $event, sprintf('Unable to get logs for project "%s".', $project));

        list($sha, $author, $date, $message) = explode("\n", trim($process->getOutput()), 4);

        $commit = $this->storage->getCommit($project, $sha);

        // commit has already been built
        if ($commit && $commit->isBuilt() && !$event->hasFlag(Sismo::FORCE_BUILD)) {
            $event->stopPropagation();

            return;
        }

        $commit = $this->storage->initCommit($project, $sha, $author, \DateTime::createFromFormat('Y-m-d H:i:s O', $date), $message);

        $event->setCommit($commit);
    }

    public function build(BuildEvent $event)
    {
        $buildDir = $this->getBuildDir($event->getProject());
        file_put_contents($buildDir.'/sismo-run-tests.sh', str_replace(array("\r\n", "\r"), "\n", $event->getProject()->getCommand()));

        $process = new Process('sh sismo-run-tests.sh', $buildDir);
        $process->setTimeout(3600);
        $process->run($event->getCallback());

        $commit = $event->getCommit();

        if (!$process->isSuccessful()) {
            $commit->setStatusCode('failed');
            $commit->setOutput(sprintf("\033[31mBuild failed\033[0m\n\n\033[33mOutput\033[0m\n%s\n\n\033[33m Error\033[0m%s", $process->getOutput(), $process->getErrorOutput()));
        } else {
            $commit->setStatusCode('success');
            $commit->setOutput($process->getOutput());
        }

        $this->storage->updateCommit($commit);
    }

    public function notify(BuildEvent $event)
    {
        if ($event->hasFlag(Sismo::SILENT_BUILD)) {
            return;
        }

        foreach ($event->getProject()->getNotifiers() as $notifier) {
            $notifier->notify($event->getCommit());
        }
    }

    public function getBuildDir(Project $project)
    {
        return $this->baseBuildDir.'/'.substr(md5($project->getRepository().$project->getBranch()), 0, 6);
    }

    protected function getGitCommand($command, Project $project, array $replace = array())
    {
        $buildDir = $this->getBuildDir($project);
        $replace = array_merge(array(
            '%repo%'        => escapeshellarg($project->getRepository()),
            '%dir%'         => escapeshellarg($buildDir),
            '%branch%'      => escapeshellarg('origin/'.$project->getBranch()),
            '%localbranch%' => escapeshellarg($project->getBranch()),
            '%format%'      => '"%H%n%an%n%ci%n%s%n"',
        ), $replace);

        return strtr($this->gitPath.' '.$this->gitCmds[$command], $replace);
    }

    private function execute($command, BuildEvent $event, $message)
    {
        if (null !== $event->getCallback()) {
            call_user_func($event->getCallback(), 'out', sprintf("Running \"%s\"\n", $command));
        }
        $process = new Process($command, $this->getBuildDir($event->getProject()));
        $process->setTimeout(3600);
        $process->run($event->getCallback());
        if (!$process->isSuccessful()) {
            throw new BuildException($message);
        }

        return $process;
    }
}
