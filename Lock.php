<?php
/**
 * Global deployment lock task for Magallanes deployment tool (http://www.magephp.com).
 *
 * @licence The MIT Licence (MIT)
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace Task;

use Mage\Task\AbstractTask;
use Mage\Task\ErrorWithMessageException;
use Mage\Task\SkipException;
use Mage\Console;

/**
 * Performs deployment lock for specified environment.
 *
 * Use as pre-deployment task, config example:
 * 
 * tasks:
 *   pre-deploy:
 *     - lock
 * 
 * @author Tomas Gluchman <tomas@gluchman.cz>
 */
class Lock extends AbstractTask
{

    /**
     * @var boolean
     */
    const LOCK = true;

    /**
     * @var boolean
     */
    const UNLOCK = false;

    /**
     * @see \Mage\Task\AbstractTask::getName()
     */
    public function getName()
    {
        $lockAction = $this->getParameter('unlock', null) ? false : $this->getParameter('lock', null);
        $name = 'Checking lock status';
        if (!is_null($lockAction)) {
            $name .= ' and performing ' . ($lockAction ? 'lock' : 'unlock');
        }
        return $name;
    }


    /**
     * @see \Mage\Task\AbstractTask::run()
     */
    public function run()
    {
        $lockAction = $this->getParameter('unlock', null) ? false : $this->getParameter('lock', null);
        $hosts = $this->getConfig()->getHosts();
        $return = true;

        if (count($hosts)) {
            foreach ($hosts as $hostKey => $host) {
                $hostConfig = null;
                if (is_array($host)) {
                    $hostConfig = $host;
                    $host = $hostKey;
                }

                $this->getConfig()->setHost($host);
                $this->getConfig()->setHostConfig($hostConfig);

                $return = $return && $this->runLockTask($lockAction);
            }
        }
        return $return;
    }


    /**
     * @param mixed $lockAction
     */
    private function runLockTask($lockAction)
    {
        $locked = $this->isLocked();

        if ($locked && $lockAction !== self::UNLOCK) {
            $message = 'Remote server locked';
            if (isset($locked['name'], $locked['email'])) {
                $message .= ' by ' . implode('/', array($locked['name'], $locked['email']));
            }
            if (isset($locked['reason'])) {
                $message .= ', reason "' . $locked['reason'] . '"';
            }
            if (isset($locked['date'])) {
                $message .= ', on ' . $locked['date'] . '';
            }
            throw new ErrorWithMessageException($message);
        }

        if ($lockAction === self::LOCK) {
            $lockData = array();

            $this->runCommandLocal('git config --get user.name', $userName);
            $this->runCommandLocal('git config --get user.email', $userEmail);

            Console::output('Your name ' . (!empty($userName) ? '[' . $userName . ']' : '(enter to leave blank)') . ': ', 0, 0);
            $lockData['name'] = Console::readInput();
            if (empty($lockData['name']) && !empty($userName)) {
                $lockData['name'] = $userName;
            }
            Console::output('Your email ' . (!empty($userEmail) ? '[' . $userEmail . ']' : '(enter to leave blank)') . ': ', 0, 0);
            $lockData['email'] = Console::readInput();
            if (empty($lockData['email']) && !empty($userEmail)) {
                $lockData['email'] = $userEmail;
            }
            Console::output('Reason of lock (enter to leave blank): ', 0, 0);
            $lockData['reason'] = Console::readInput();
            $lockData['date'] = date('Y-m-d H:i:s');

            $command = sprintf('echo "%s" > .mage_lock', base64_encode(json_encode($lockData)));

            if ($this->runCommandRemote($command)) {
                Console::output('<green>Environment ' . $this->getConfig()->getEnvironment() . ' locked for deploy</green>');

            } else {
                Console::output('<red>Environment ' . $this->getConfig()->getEnvironment() . ' failed to lock for deploy</red>');
            }
            return false;
        }

        if ($lockAction === self::UNLOCK) {
            if ($this->runCommandRemote('rm -f .mage_lock')) {
                Console::output('<green>Environment ' . $this->getConfig()->getEnvironment() . ' unlocked for deploy</green>');

            } else {
                Console::output('<red>Environment ' . $this->getConfig()->getEnvironment() . ' failed to unlock for deploy</red>');
            }
            return false;
        }

        return true;
    }


    /**
     * @return boolean
     */
    private function isLocked()
    {
        $this->runCommandRemote('[ -f .mage_lock ] && cat .mage_lock', $output);
        if (empty($output)) {
            return false;
        }
        $message = json_decode(base64_decode($output), true);
        if (is_array($message)) {
            return $message;
        }
        Console::output('<red>Remote server lock corrupted</red>');
        throw new SkipException('Remote server lock corrupted');
    }

}
