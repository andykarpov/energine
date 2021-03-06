<?php
/**
 * @file
 * ComponentContainer.
 *
 * It contains the definition to:
 * @code
class ComponentContainer
 * @endcode
 *
 * @author dr.Pavka
 * @copyright Energine 2010
 *
 * @version 1.0.0
 */
namespace Energine\share\gears;
/**
 * Container of components.
 *
 * @code
class ComponentContainer
 * @endcode
 */
class ComponentContainer extends Primitive implements IBlock, \Iterator {
    /**
     * Defines whether the component container is enabled.
     * @var bool $enabled
     */
    private $enabled = true;
    /**
     * Properties.
     * @var array $properties
     */
    private $properties = [];
    /**
     * Container name.
     * @var string $name
     */
    private $name;
    /**
     * Array of IBlock.
     * @var array $blocks
     */
    private $blocks = [];
    /**
     * Iterator index.
     * @var int $iteratorIndex
     */
    private $iteratorIndex = 0;
    /**
     * Array of child names.
     * @var array $childNames
     */
    private $childNames = [];
    /**
     * @var Document $document
     */
    private $document;
    /**
     * @var string
     */
    private $value = null;

    /**
     * @param string $name Component name.
     * @param array $properties Component properties.
     * @param string $value Node value
     */
    public function __construct($name, array $properties = [], $value = null) {
        $this->name = $name;
        $this->document = E()->getDocument();

        $this->properties = $properties;
        if (!isset($this->properties['tag'])) {
            $this->properties['tag'] = 'container';
        }
        if ($this->properties['tag'] == 'page') {
            $this->properties['tag'] = 'layout';
        }
        $this->document->componentManager->register($this);
        $this->value = $value;
    }

    /**
     * Add block.
     * @param IBlock $block New block.
     */
    public function add(IBlock $block) {
        $this->blocks[$block->getName()] = $block;
    }

    /**
     * Create component container from description.
     *
     * @param \SimpleXMLElement $containerDescription Container description.
     * @param array $externalParams Additional attributes.
     * @return ComponentContainer
     *
     * @throws SystemException ERR_NO_CONTAINER_NAME
     */
    static public function createFromDescription(\SimpleXMLElement $containerDescription, array $externalParams = []) {
        $properties['tag'] = $containerDescription->getName();

        $attributes = $containerDescription->attributes();
        if (in_array($containerDescription->getName(), ['page', 'content'])) {
            $properties['name'] = $properties['tag'];
        } elseif (!isset($attributes['name'])) {
            $properties['name'] = uniqid('name_');
        }

        foreach ($attributes as $propertyName => $propertyValue) {
            $properties[(string)$propertyName] = (string)$propertyValue;
        }
        $name = $properties['name'];
        unset($properties['name']);
        $properties = array_merge($properties, $externalParams);
        $value = null;
        $containerDescriptionValue = trim((string)$containerDescription);
        if(!empty($containerDescriptionValue)){
            $value = $containerDescriptionValue;
        }
        $result = new ComponentContainer($name, $properties, $value);
        foreach ($containerDescription as $blockDescription) {
            if($c = ComponentManager::createBlockFromDescription($blockDescription, $externalParams))
                $result->add($c);
        }

        return $result;
    }

    /**
     * Check if the container is empty.
     * @return bool
     */
    public function isEmpty() {
        return (boolean)sizeof($this->childs);
    }

    public function getName() {
        return $this->name;
    }

    /**
     * Set property to the ComponentContainer::$properties.
     *
     * @param string $propertyName Property name.
     * @param string $propertyValue Property value.
     */
    public function setProperty($propertyName, $propertyValue) {
        $this->properties[(string)$propertyName] = (string)$propertyValue;
    }

    /**
     * Get property value from ComponentContainer::$properties by property name.
     *
     * @param string $propertyName Property name.
     * @return string or null
     */
    public function getProperty($propertyName) {
        $result = NULL;
        if (isset($this->properties[$propertyName])) {
            $result = $this->properties[$propertyName];
        }
        return $result;
    }

    /**
     * Remove property from ComponentContainer::$properties.
     *
     * @param string $propertyName Property name.
     */
    public function removeProperty($propertyName) {
        unset($this->properties[$propertyName]);
    }

    /**
     * Build DOM document.
     *
     * @return DOMElement|array
     */
    public function build() {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $containerDOM = $doc->createElement($this->properties['tag'], $this->value);
        if (in_array($this->properties['tag'], ['page', 'content', 'container']))
            $containerDOM->setAttribute('name', $this->getName());

        $doc->appendChild($containerDOM);
        foreach ($this->properties as $propertyName => $propertyValue) {
            if ($propertyName != 'tag') {
                $containerDOM->setAttribute($propertyName, $propertyValue);
            }
        }
        foreach ($this->blocks as $block) {
            if (
                $block->enabled()
                &&
                ($this->document->getRights() >= $block->getCurrentStateRights())
            ) {
                $blockDOM = $block->build();
                if ($blockDOM instanceof \DOMDocument) {
                    $blockDOM =
                        $doc->importNode($blockDOM->documentElement, true);
                    $containerDOM->appendChild($blockDOM);
                }
            }
        }

        return $doc;
    }

    /**
     * Call @c run() method for all @link ComponentContainer::$blocks blocks@endlink.
     */
    public function run() {
        foreach ($this->blocks as $block) {
            if ($block->enabled()
                && ($this->document->getRights() >= $block->getCurrentStateRights())
            ) {
                $block->run();
            }
        }
    }

    public function rewind() {
        $this->childNames = array_keys($this->blocks);
        $this->iteratorIndex = 0;
    }

    public function valid() {
        return isset($this->childNames[$this->iteratorIndex]);
    }

    public function key() {
        return $this->childNames[$this->iteratorIndex];
    }

    public function next() {
        $this->iteratorIndex++;
    }

    public function current() {
        return $this->blocks[$this->childNames[$this->iteratorIndex]];
    }

    /**
     * Disable ComponentContainer.
     */
    public function disable() {
        $this->enabled = false;

        foreach ($this->blocks as $block) {
            $block->disable();
        }
    }

    public function enabled() {
        return $this->enabled;
    }

    public function getCurrentStateRights() {
        return 0;
    }
}
