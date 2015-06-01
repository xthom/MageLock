# MageLock
Global deployment lock task for Magallanes deployment tool

## Installation

    composer require 'tomasgluchman/mage-lock:~0.0'

## Usage

### Config:

    tasks:
      pre-deploy:
        - lock

### CLI:

    mage deploy to:production --lock

    mage deploy to:production --unlock

Due to the limits of implementation, the lock/unlock task will always return FAIL message. However, if the green-coloured message "`Environment %environment% (un)locked for deploy`" is present, the lock/unlock was successful.
