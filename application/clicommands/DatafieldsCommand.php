<?php

namespace Icinga\Module\Director\Clicommands;

use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\Objects\IcingaObject;


/**
 * Manage Icinga DataFields
 *
 * Use this command to list Icinga DataFields
 */
class DatafieldsCommand extends Command
{

    protected $type;

    private $objects;

    public function listAction()
    {
        $db = $this->db();
        $result = array();
        foreach ($this->getObjects() as $o) {

            $result[] = $o->get('varname');
        }

        sort($result);

        if ($this->params->shift('json')) {
            echo $this->renderJson($result, !$this->params->shift('no-pretty'));
        } else {
            foreach ($result as $name) {
                echo $name . "\n";
            }
        }
    }

    protected function getObjects()
    {
        if ($this->objects === null) {
            $this->objects = IcingaObject::loadAllByType(
                $this->getType(),
                $this->db(),
                null,
                'id'
            );
        }

        return $this->objects;
    }

    protected function getType()
    {
        if ($this->type === null) {
            // Extract the command class name...
            $className = substr(strrchr(get_class($this), '\\'), 1);
            // ...and strip the Command extension
            $this->type = rtrim(substr($className, 0, -7), 's');
        }

        return $this->type;
    }

}
