<?php

namespace Icinga\Module\Director\Forms;

use Icinga\Data\Filter\Filter;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Director\Data\PropertiesFilter\ArrayCustomVariablesFilter;
use Icinga\Module\Director\Exception\NestingError;
use Icinga\Module\Director\Web\Form\DirectorObjectForm;
use Icinga\Module\Director\Objects\IcingaHost;
use Icinga\Module\Director\Objects\IcingaService;
use Icinga\Module\Director\Objects\IcingaServiceSet;
use Icinga\Module\Director\Web\Form\Validate\NamePattern;
use dipl\Html\Html;
use dipl\Html\Link;

class IcingaServiceForm extends DirectorObjectForm
{
    /** @var IcingaHost */
    private $host;

    /** @var IcingaServiceSet */
    private $set;

    private $apply;

    /** @var IcingaService */
    protected $object;

    /** @var IcingaService */
    private $applyGenerated;

    private $inheritedFrom;

    public function setApplyGenerated(IcingaService $applyGenerated)
    {
        $this->applyGenerated = $applyGenerated;
        return $this;
    }

    public function setInheritedFrom($hostname)
    {
        $this->inheritedFrom = $hostname;
        return $this;
    }

    public function setup()
    {
        if ($this->providesOverrides()) {
            return;
        }

        if ($this->host && $this->set) {
            $this->setupOnHostForSet();
            return;
        }

        try {
            if (!$this->isNew() && $this->host === null) {
                $this->host = $this->object->getResolvedRelated('host');
            }
        } catch (NestingError $nestingError) {
            // ignore for the form to load
        }

        if ($this->set !== null) {
            $this->setupSetRelatedElements();
        } elseif ($this->host === null) {
            $this->setupServiceElements();
        } else {
            $this->setupHostRelatedElements();
        }
    }

    protected function providesOverrides()
    {
        return $this->applyGenerated
            || $this->inheritedFrom
            || ($this->host && $this->set)
            || ($this->object && $this->object->usesVarOverrides());
    }

    protected function onAddedFields()
    {
        if (! $this->providesOverrides()) {
            return;
        }

        $this->addOverrideHint();

        $group = $this->getDisplayGroup('custom_fields');

        if ($group) {
            $elements = $group->getElements();
            $group->setElements([$this->getElement('inheritance_hint')]);
            $group->addElements($elements);
            $this->setSubmitLabel(
                $this->translate('Override vars')
            );
        } else {
            $this->addElementsToGroup(
                array('inheritance_hint'),
                'custom_fields',
                20,
                $this->translate('Hints regarding this service')
            );

            $this->setSubmitLabel(false);
        }
    }

    public function createApplyRuleFor(IcingaService $service)
    {
        $this->apply = $service;
        $object = $this->object();
        $object->set('imports', $service->getObjectName());
        $object->object_type = 'apply';
        $object->object_name = $service->object_name;
        return $this;
    }

    protected function setupServiceElements()
    {
        if ($this->object) {
            $this->addHidden('object_type', $this->object->object_type);
        } elseif ($this->preferredObjectType) {
            $this->addHidden('object_type', $this->preferredObjectType);
        } else {
            $this->addHidden('object_type', 'template');
        }

        $this->addNameElement()
             ->addHostObjectElement()
             ->addImportsElement()
             ->addChoices('service')
             ->addGroupsElement()
             ->addDisabledElement()
             ->addApplyForElement()
             ->groupMainProperties()
             ->addAssignmentElements()
             ->addCheckCommandElements()
             ->addCheckExecutionElements()
             ->addExtraInfoElements()
             ->addAgentAndZoneElements()
             ->setButtons();
    }

    protected function addOverrideHint()
    {
        if ($this->object && $this->object->usesVarOverrides()) {
            $hint = $this->translate(
                'This service has been generated in an automated way, but still'
                . ' allows you to override the following properties in a safe way.'
            );
        } elseif ($apply = $this->applyGenerated) {
            $hint = Html::sprintf(
                $this->translate(
                    'This service has been generated using the %s apply rule, assigned where %s'
                ),
                Link::create(
                    $apply->getObjectName(),
                    'director/service',
                    ['id' => $apply->get('id')],
                    ['data-base-target' => '_next']
                ),
                (string) Filter::fromQueryString($apply->assign_filter)
            );
        } elseif ($this->host && $this->set) {
            $hint = Html::sprintf(
                $this->translate(
                    'This service belongs to the %s Service Set. Still, you might want'
                    . ' to override the following properties for this host only.'
                ),
                Link::create(
                    $this->set->getObjectName(),
                    'director/serviceset',
                    ['id' => $this->set->get('id')],
                    ['data-base-target' => '_next']
                )
            );
        } elseif ($this->inheritedFrom) {
            $msg = $this->translate(
                'This service has been inherited from %s. Still, you might want'
                . ' to change the following properties for this host only.'
            );

            $name = $this->inheritedFrom;
            $link = Link::create(
                $name,
                'director/service',
                [
                    'host' => $name,
                    'name' => $this->object->getObjectName(),
                ],
                ['data-base-target' => '_next']
            );

            $hint = Html::sprintf($msg, $link);
        } else {
            throw new ProgrammingError('Got no override hint for your situation');
        }

        $this->setSubmitLabel($this->translate('Override vars'));

        $this->addHtmlHint($hint, ['name' => 'inheritance_hint']);
    }

    protected function setupOnHostForSet()
    {
        $msg = $this->translate(
            'This service belongs to the service set "%s". Still, you might want'
            . ' to change the following properties for this host only.'
        );

        $name = $this->set->getObjectName();
        $link = Link::create(
            $name,
            'director/serviceset',
            ['name' => $name],
            ['data-base-target' => '_next']
        );

        $this->addHtmlHint(
            Html::sprintf($msg, $link),
            ['name' => 'inheritance_hint']
        );

        $this->addElementsToGroup(
            ['inheritance_hint'],
            'custom_fields',
            50,
            $this->translate('Custom properties')
        );

        $this->setSubmitLabel($this->translate('Override vars'));
    }

    protected function addAssignmentElements()
    {
        $this->addAssignFilter(array(
            'columns' => IcingaHost::enumProperties($this->db, 'host.'),
            'required' => true,
            'description' => $this->translate(
                'This allows you to configure an assignment filter. Please feel'
                . ' free to combine as many nested operators as you want'
            )
        ));

        return $this;
    }

    protected function setupHostRelatedElements()
    {
        $this->addHidden('host_id', $this->host->id);
        $this->addHidden('object_type', 'object');
        $this->addImportsElement();
        $imports = $this->getSentOrObjectValue('imports');

        if ($this->hasBeenSent()) {
            $imports = $this->getElement('imports')->setValue($imports)->getValue();
        }

        if ($this->isNew() && empty($imports)) {
            $this->groupMainProperties();
            return;
        }

        $this->addNameElement()
             ->addChoices('service')
             ->addDisabledElement()
             ->groupMainProperties()
             ->addCheckCommandElements()
             ->addExtraInfoElements()
             ->setButtons();

        if ($this->hasBeenSent()) {
            $name = $this->getSentOrObjectValue('object_name');
            if (!strlen($name)) {
                $this->setElementValue('object_name', end($imports));
                $this->object->object_name = end($imports);
            }
        }
    }

    /**
     * @param IcingaHost $host
     * @return $this
     */
    public function setHost(IcingaHost $host)
    {
        $this->host = $host;
        return $this;
    }

    protected function setupSetRelatedElements()
    {
        $this->addHidden('service_set_id', $this->set->id);
        $this->addHidden('object_type', 'apply');
        $this->addImportsElement();
        $imports = $this->getSentOrObjectValue('imports');

        if ($this->hasBeenSent()) {
            $imports = $this->getElement('imports')->setValue($imports)->getValue();
        }

        if ($this->isNew() && empty($imports)) {
            $this->groupMainProperties();
            return;
        }

        $this->addNameElement()
             ->addDisabledElement()
             ->groupMainProperties();

        if ($this->hasPermission('director/admin')) {
            $this->addCheckCommandElements(true)
                ->addCheckExecutionElements(true)
                ->addExtraInfoElements()
                ->setButtons();
        }

        if ($this->hasBeenSent()) {
            $name = $this->getSentOrObjectValue('object_name');
            if (!strlen($name)) {
                $this->setElementValue('object_name', end($imports));
                $this->object->object_name = end($imports);
            }
        }
    }

    public function setServiceSet(IcingaServiceSet $set)
    {
        $this->set = $set;
        return $this;
    }

    protected function addNameElement()
    {
        $this->addElement('text', 'object_name', array(
            'label'       => $this->translate('Name'),
            'required'    => !$this->object()->isApplyRule(),
            'description' => $this->translate(
                'Name for the Icinga service you are going to create'
            )
        ));

        if ($this->object()->isApplyRule()) {
            $rName = 'director/service/apply/filter-by-name';
            foreach ($this->getAuth()->getRestrictions($rName) as $restriction) {
                $this->getElement('object_name')->addValidator(
                    new NamePattern($restriction)
                );
            }
        }

        return $this;
    }

    protected function addHostObjectElement()
    {
        if ($this->isObject()) {
            $this->addElement('select', 'host_id', array(
                'label'       => $this->translate('Host'),
                'required'    => true,
                'multiOptions' => $this->optionalEnum($this->enumHostsAndTemplates()),
                'description' => $this->translate(
                    'Choose the host this single service should be assigned to'
                )
            ));
        }

        return $this;
    }

    protected function addApplyForElement()
    {
        if ($this->object->isApplyRule()) {
            $hostProperties = IcingaHost::enumProperties(
                $this->object->getConnection(),
                'host.',
                new ArrayCustomVariablesFilter()
            );

            $this->addElement('select', 'apply_for', array(
                'label' => $this->translate('Apply For'),
                'class' => 'assign-property autosubmit',
                'multiOptions' => $this->optionalEnum($hostProperties, $this->translate('None')),
                'description' => $this->translate(
                    'Evaluates the apply for rule for ' .
                    'all objects with the custom attribute specified. ' .
                    'E.g selecting "host.vars.custom_attr" will generate "for (config in ' .
                    'host.vars.array_var)" where "config" will be accessible through "$config$". ' .
                    'NOTE: only custom variables of type "Array" are eligible.'
                )
            ));
        }

        return $this;
    }

    protected function addGroupsElement()
    {
        $groups = $this->enumServicegroups();

        if (! empty($groups)) {
            $this->addElement('extensibleSet', 'groups', array(
                'label'        => $this->translate('Groups'),
                'multiOptions' => $this->optionallyAddFromEnum($groups),
                'positional'   => false,
                'description'  => $this->translate(
                    'Service groups that should be directly assigned to this service.'
                    . ' Servicegroups can be useful for various reasons. They are'
                    . ' helpful to provided service-type specific view in Icinga Web 2,'
                    . ' either for custom dashboards or as an instrument to enforce'
                    . ' restrictions. Service groups can be directly assigned to'
                    . ' single services or to service templates.'
                )
            ));
        }

        return $this;
    }

    protected function addAgentAndZoneElements()
    {
        if (!$this->isTemplate()) {
            return $this;
        }

        $this->optionalBoolean(
            'use_agent',
            $this->translate('Run on agent'),
            $this->translate(
                'Whether the check commmand for this service should be executed'
                . ' on the Icinga agent'
            )
        );
        $this->addZoneElement();

        $elements = array(
            'use_agent',
            'zone_id',
        );
        $this->addDisplayGroup($elements, 'clustering', array(
            'decorators' => array(
                'FormElements',
                array('HtmlTag', array('tag' => 'dl')),
                'Fieldset',
            ),
            'order' => 40,
            'legend' => $this->translate('Icinga Agent and zone settings')
        ));

        return $this;
    }

    protected function enumHostsAndTemplates()
    {
        return array(
            $this->translate('Templates') => $this->db->enumHostTemplates(),
            $this->translate('Hosts')     => $this->db->enumHosts(),
        );
    }

    protected function enumServicegroups()
    {
        $db = $this->db->getDbAdapter();
        $select = $db->select()->from(
            'icinga_servicegroup',
            array(
                'name'    => 'object_name',
                'display' => 'COALESCE(display_name, object_name)'
            )
        )->where('object_type = ?', 'object')->order('display');

        return $db->fetchPairs($select);
    }

    protected function succeedForOverrides()
    {
        $vars = array();
        foreach ($this->object->vars() as $key => $var) {
            $vars[$key] = $var->getValue();
        }

        $host = $this->host;
        $serviceName = $this->object->getObjectName();

        $this->host->overrideServiceVars($serviceName, (object) $vars);

        if ($host->hasBeenModified()) {
            $msg = sprintf(
                empty($vars)
                ? $this->translate('All overrides have been removed from "%s"')
                : $this->translate('The given properties have been stored for "%s"'),
                $this->translate($host->getObjectName())
            );

            $host->store();
        } else {
            if ($this->isApiRequest()) {
                $this->setHttpResponseCode(304);
            }

            $msg = $this->translate('No action taken, object has not been modified');
        }

        $this->redirectOnSuccess($msg);
    }

    public function onSuccess()
    {
        if ($this->providesOverrides()) {
            return $this->succeedForOverrides();
        }

        return parent::onSuccess();
    }
}
