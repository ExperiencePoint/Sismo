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

// @codeCoverageIgnoreStart
/**
 * Builds commits.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Builder
{
    public function build(Project $project)
    {
        $event = new BuildEvent($this->project);
        $this->dispatcher->dispatch(BuildEvents::PRE_BUILD, $event);
        $this->dispatcher->dispatch(BuildEvents::BUILD, $event);
        $this->dispatcher->dispatch(BuildEvents::POST_BUILD, $event);
    }
}
// @codeCoverageIgnoreEnd
