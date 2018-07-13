<?php

/*
 * This file is part of the Ampersand backend framework.
 *
 */

namespace Ampersand\Interfacing;

use stdClass;
use ArrayAccess;
use IteratorAggregate;
use Exception;
use Ampersand\Core\Atom;
use Ampersand\Core\Concept;
use Ampersand\Log\Logger;
use function Ampersand\Misc\isSequential;
use Ampersand\Misc\Config;
use Ampersand\Interfacing\Options;
use Ampersand\Interfacing\InterfaceTxtObject;
use Ampersand\Model\InterfaceObjectFactory;
use Ampersand\Interfacing\InterfaceObjectInterface;

/**
 *
 * @author Michiel Stornebrink (https://github.com/Michiel-s)
 *
 */
class Resource extends Atom implements ArrayAccess, IteratorAggregate
{
    /**
     * Interface for this resource.
     * The interface defines which properties and methods the resource has.
     * Interface definitions are generated by the Ampersand prototype generator.
     *
     * @var \Ampersand\Interfacing\InterfaceObjectInterface
     */
    protected $ifc;

    /**
     * The parent resource (or null when $this resource is entry resource)
     * @var \Ampersand\Interfacing\Resource
     */
    protected $parent = null;
    
    /**
     * Label of resource to be displayed in user interfaces
     * @var string
     */
    protected $label = null;
    
    /**
     * Contains view data of this resource for the UI templates
     * DO NOT initialize var here, isset() is used below
     * @var array $viewData
     */
    protected $viewData;
    
    /**
     * Contains the interface data filled by the get() method
     * @var array|null $ifcData
     */
    protected $ifcData = null;

    /**
     * The path of this resource (including interface and path to parent resource)
     *
     * @var string
     */
    protected $path;

    /**
     * Specifies if user interface data must be included when outputting (json_serialize) the Resource
     * This includes: _id_, _label_ and _view_
     *
     * @var boolean
     */
    protected $inclUserInterfaceData = false;
    
    /**
     * Constructor
     *
     * @param string $resourceId Ampersand atom identifier
     * @param \Ampersand\Core\Concept $cpt
     * @param \Ampersand\Interfacing\InterfaceObjectInterface $ifc
     * @param \Ampersand\Interfacing\Resource $parent
     */
    public function __construct(string $resourceId, Concept $cpt, InterfaceObjectInterface $ifc, Resource $parent = null)
    {
        if (!$cpt->isObject()) {
            throw new Exception("Cannot instantiate resource, because its type '{$cpt}' is a non-object concept", 400);
        }
        
        // Call Atom constructor
        parent::__construct(rawurldecode($resourceId), $cpt); // url decode resource identifier

        $this->ifc = $ifc;
        $this->parent = $parent;
        $this->setPath();
    }

    /**
     * Function is called when object is treated as a string
     * This functionality is needed when the ArrayAccess::offsetGet method below is used by internal code
     *
     * @return string
     */
    public function __toString()
    {
        return (string) parent::jsonSerialize();
    }
    
    /**
     * Returns label (from view or atom id) for this atom
     * @return string
     */
    public function getLabel(): string
    {
        if (!isset($this->label)) {
            $viewStr = implode($this->getView());
            $this->label = empty(trim($viewStr)) ? $this->id : $viewStr; // empty view => label = id
        }
        return $this->label;
    }
    
    /**
     * Function is called when object encoded to json with json_encode()
     *
     * @return array|string
     */
    public function jsonSerialize()
    {
        $content = [];
        if ($this->inclUserInterfaceData) {
            // Add Ampersand atom attributes
            $content['_id_'] = $this->id;
            $content['_label_'] = $this->getLabel();
            $content['_path_'] = $this->getPath();
        
            // Add view data if array is assoc (i.e. not sequential)
            $data = $this->getView();
            if (!isSequential($data)) {
                $content['_view_'] = $data;
            }
        // Not inclUserInterfaceData and ifcData is null -> directly return $this->id
        } elseif (is_null($this->ifcData)) {
            return $this->id;
        }
        
        // Merge with inerface data (which is set when get() method is called before)
        return array_merge($content, (array)$this->ifcData); // cast to array: null => empty array
    }
    
    /**
     * Returns view array of key-value pairs for this atom
     * @return array
     */
    private function getView()
    {
        // If view is not already set
        if (!isset($this->viewData)) {
            $this->viewData = $this->ifc->getViewData($this);
        }
        return $this->viewData;
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    protected function setPath(): string
    {
        if (is_null($this->parent)) {
            if ($this->concept->isSession()) {
                $this->path = "session"; // Don't put session id here, this is implicit
            } else {
                $this->path = "resource/{$this->concept->name}/" . $this->id;
            }
        } else {
            /* Skip resource id for ident interface expressions (I[Concept])
            * I expressions are commonly used for adding structure to an interface using (sub) boxes
            * This results in verbose paths
            * e.g.: pathToApi/resource/Person/John/Person/John/Person details/John/Name
            * By skipping ident expressions the paths are more concise without loosing information
            * e.g.: pathToApi/resource/Person/John/Person/PersonDetails/Name
            */
            if ($this->ifc->isIdent()) {
                $this->path = $parent->getPath();
            } else {
                $this->path = $parent->getPath() . '/' . $this->ifc->getIfcId() . '/' . $this->id;
            }
        }
        return $this->path;
    }
    
    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Returns parent resource, or null is not specified (i.e. $this is entry resource)
     *
     * @return \Ampersand\Interfacing\Resource
     */
    public function getParent(): Resource
    {
        if (is_null($this->parent)) {
            throw new Exception("Parent resource not provided", 400);
        }
        return $this->parent;
    }
    
    /**
     * Return resource representation of given interface and target atom
     *
     * @param string $ifcId
     * @param string $tgtId
     * @return \Ampersand\Interfacing\Resource
     */
    public function one($ifcId, $tgtId): Resource
    {
        return $this->all($ifcId)->one($tgtId);
    }
    
    /**
     * Return resource list with target atoms of given interface
     * For system use of interfaces you can skip the role check by setting the second parameters to true
     *
     * @param string $ifcId
     * @param bool $skipAccessCheck
     * @return \Ampersand\Interfacing\ResourceList
     */
    public function all($ifcId, bool $skipAccessCheck = false): ResourceList
    {
        $ifc = $this->ifc->getSubinterface($ifcId);
        
        return new ResourceList($this, $ifc, $skipAccessCheck);
    }

    /**
     * Walk path from this resource. Path must end/result in a Resource
     * Use Resource::walkPathToResourceList if path must end in a ResourceList
     *
     * @param string|array $path
     * @return \Ampersand\Interfacing\Resource
     */
    public function walkPathToResource($path) : Resource
    {
        $r = $this->walkPath($path);

        // For ident interface expressions the Resource id is left out of the path. Therefore,
        // automatically step into the (only possible) target resource
        if (get_class($r) === 'Ampersand\Interfacing\ResourceList' && $r->getIfc()->isIdent()) {
            $r = $r->one();
        }

        // Check if correct object is returned (Resource vs ResourceList)
        if (get_class($r) === 'Ampersand\Interfacing\Resource') {
            return $r;
        } else {
            throw new Exception("Provided path '{$path}' MUST end with a resource identifier", 400);
        }
    }

    /**
     * Walk path from this resource. Path must end/result in a ResourceList
     * Use Resource::walkPathToResource if path must end in a Resource
     *
     * @param string|array $path
     * @return \Ampersand\Interfacing\ResourceList
     */
    public function walkPathToResourceList($path): ResourceList
    {
        $r = $this->walkPath($path);

        // Check if correct object is returned (Resource vs ResourceList)
        if (get_class($r) === 'Ampersand\Interfacing\ResourceList') {
            return $r;
        } else {
            throw new Exception("Provided path '{$path}' MUST NOT end with a resource identifier", 400);
        }
    }
    
    /**
     * Walk path from this resource to either a Resource or a ResourceList
     *
     * @param string|array $path
     * @return \Ampersand\Interfacing\Resource|\Ampersand\Interfacing\ResourceList
     */
    public function walkPath($path)
    {
        // Prepare path list
        if (is_array($path)) {
            $path = implode('/', $path);
        }
        $path = trim($path, '/'); // remove root slash (e.g. '/Projects/xyz/..') and trailing slash (e.g. '../Projects/xyz/')
        
        if ($path === '') {
            $pathList = []; // support no path
        } else {
            $pathList = explode('/', $path);
        }

        // Try to create resource ($this) if not exists (yet)
        if (!$this->exists()) {
            // Automatically create if allowed
            if ($this->ifc->crudC()) {
                $this->add();
            } else {
                throw new Exception("Resource '{$this}' not found", 404);
            }
        }

        // Walk path by alternating between $r = Resource and $r = ResourceList
        $r = $this; // start with resource ($this)
        while (count($pathList)) {
            switch (get_class($r)) {
                case 'Ampersand\Interfacing\Resource':
                    $r = $r->all(array_shift($pathList));
                    break;
                case 'Ampersand\Interfacing\ResourceList':
                    // See explaination in setPath() method above why this if/else construct is here
                    if ($r->getIfc()->isIdent()) {
                        $r = $r->one();
                    } else {
                        $r = $r->one(array_shift($pathList));
                    }
                    break;
                default:
                    throw new Exception("Unknown class type: " . get_class($r), 500);
            }
        }
        
        // Return
        return $r;
    }

/**************************************************************************************************
 * ArrayAccess methods
 *************************************************************************************************/
    public function offsetExists($offset)
    {
        // Get data (1 level deep) if icfData is not (yet) set
        if (is_null($this->ifcData)) {
            $this->get(Options::INCLUDE_REF_IFCS | Options::INCLUDE_LINKTO_IFCS, 1);
        }
        return isset($this->ifcData[$offset]);
    }

    public function offsetGet($offset)
    {
        // Get data (1 level deep) if icfData is not (yet) set
        if (is_null($this->ifcData)) {
            $this->get(Options::INCLUDE_REF_IFCS | Options::INCLUDE_LINKTO_IFCS, 1);
        }

        if ($this->ifc->getSubinterface($offset)->isUni()) {
            // Value can be Atom/Resource or scalar
            return $this->ifcData[$offset]->id ?? $this->ifcData[$offset] ?? null;
        } else {
            return array_map(function (Resource $resource) {
                return $resource->id ?? $resource; // value can be Atom/Resource or scalar
            }, $this->ifcData[$offset]);
        }
    }

    public function offsetSet($offset, $value)
    {
        throw new Exception("ArrayAccess::offsetSet() not implemented on Resource class", 500);
    }

    public function offsetUnset($offset)
    {
        throw new Exception("ArrayAccess::offsetUnset() not implemented on Resource class", 500);
    }

/**************************************************************************************************
 * ArrayAccess methods
 *************************************************************************************************/
    public function getIterator()
    {
        throw new Exception("It is not possible to iterate over a single Resource", 500);
    }

/**************************************************************************************************
 * Methods to call on Resource
 *************************************************************************************************/
 
    /**
     * Get resource data according to provided interface
     * @param int $options
     * @param int|null $depth
     * @param array $recursionArr
     * @return mixed
     */
    public function get(int $options = Options::DEFAULT_OPTIONS, int $depth = null, array $recursionArr = [])
    {
        return $this->ifc->get($this, $options, $depth, $recursionArr);
    }

    public function getProperty(string $ifcId)
    {
        return $this->ifc->getProperty($this, $ifcId);
    }
    
    /**
     * Update a resource (updates only first level of subinterfaces, for now)
     * @param \stdClass|null $resourceToPut
     * @return \Ampersand\Interfacing\Resource $this
     */
    public function put(stdClass $resourceToPut = null): Resource
    {
        if (!isset($resourceToPut)) {
            return $this; // nothing to do
        }

        // Perform PUT using the interface definition
        $this->ifc->put($this, $resourceToPut);
        
        // Clear query data
        $this->setQueryData(null);
        
        return $this;
    }
    
    /**
     * Patch this resource with provided patches
     * Use JSONPatch specification for $patches (see: http://jsonpatch.com/)
     *
     * @param array $patches
     * @return \Ampersand\Interfacing\Resource $this
     */
    public function patch(array $patches): Resource
    {
        foreach ($patches as $key => $patch) {
            if (!property_exists($patch, 'op')) {
                throw new Exception("No 'op' (i.e. operation) specfied for patch #{$key}", 400);
            }
            if (!property_exists($patch, 'path')) {
                throw new Exception("No 'path' specfied for patch #{$key}", 400);
            }
            
            // Process patch
            switch ($patch->op) {
                case "replace":
                    if (!property_exists($patch, 'value')) {
                        throw new Exception("Cannot patch replace. No 'value' specfied for patch #{$key}", 400);
                    }
                    $this->walkPathToResourceList($patch->path)->replace($patch->value);
                    break;
                case "add":
                    if (!property_exists($patch, 'value')) {
                        throw new Exception("Cannot patch add. No 'value' specfied for patch #{$key}", 400);
                    }
                    $this->walkPathToResourceList($patch->path)->add($patch->value);
                    break;
                case "remove":
                    // Regular json patch remove operation, uses last part of 'path' attribuut as resource to remove from list
                    if (!property_exists($patch, 'value')) {
                        $resource = $this->walkPathToResource($patch->path);
                        $resource->ifc->remove($resource->getParent(), $resource->id);
                    
                    // Not part of official json path specification. Uses 'value' attribute that must be removed from list
                    } elseif (property_exists($patch, 'value')) {
                        $this->walkPathToResourceList($patch->path)->remove($patch->value);
                    }
                    break;
                default:
                    throw new Exception("Unknown patch operation '{$patch->op}'. Supported are: 'replace', 'add' and 'remove'", 501);
            }
        }
        
        // Clear query data
        $this->setQueryData(null);
        
        return $this;
    }
    
    /**
     * Delete this resource and remove as target atom from current interface
     * @return \Ampersand\Interfacing\Resource $this
     */
    public function delete(): Resource
    {
        // Perform DELETE using the interface definition
        $this->ifc->delete($this);
        
        return $this;
    }
    
/**************************************************************************************************
 * Redirect for methods to call on ResourceList
 *************************************************************************************************/
    
    /**
     * Get representation of resource content given a certain interface
     *
     * @param string $ifcId
     * @param int $options
     * @param int|null $depth
     * @param array $recursionArr
     * @return bool|null|\Ampersand\Interfacing\Resource|\Ampersand\Interfacing\Resource[]
     */
    public function getList(string $ifcId, int $options = Options::DEFAULT_OPTIONS, int $depth = null, array $recursionArr = [])
    {
        return $this->all($ifcId)->get($options, $depth, $recursionArr);
    }
    
    /**
     * Create and return a new resource as target atom to given interface
     *
     * @param string $ifcId
     * @param \stdClass $resourceToPost
     * @return \Ampersand\Interfacing\Resource
     */
    public function post(string $ifcId, stdClass $resourceToPost): Resource
    {
        return $this->all($ifcId)->post($resourceToPost);
    }
    
    /**
     * Set provided value for univalent sub interface
     * @param string $ifcId
     * @param string $value (value null is supported)
     * @return boolean
     */
    public function set($ifcId, $value)
    {
        return $this->all($ifcId)->set($value);
    }
    
    /**
     * Set sub interface to null
     * @param string $ifcId
     * @return boolean
     */
    public function unset($ifcId)
    {
        return $this->all($ifcId)->set(null);
    }
    
    /**
     * Add provided value to sub interface
     * @param string $ifcId
     * @param string $value
     * @return boolean
     */
    public function push($ifcId, $value)
    {
        return $this->all($ifcId)->add($value);
    }
    
/**********************************************************************************************
 * Static functions
 *********************************************************************************************/
    
    /**
     * Return all resources for a given resourceType
     * TODO: refactor when resources (e.g. for update field in UI) can be requested with interface definition
     * @param string $resourceType name/id of concept
     * @return Resource[]
     */
    public static function getAllResources($resourceType)
    {
        $concept = Concept::getConcept($resourceType);
        
        if (!$concept->isObject()) {
            throw new Exception("Cannot get resource(s) given non-object concept {$concept}.", 500);
        }
        
        $resources = [];
        foreach ($concept->getAllAtomObjects() as $atom) {
            $r = new Resource($atom->id, $concept, InterfaceObjectFactory::getNullObject(), null);
            $r->setQueryData($atom->getQueryData());
            $resources[] = $r->get();
        }
        
        return $resources;
    }

    /**
     * Factory function for Resource class
     *
     * @param string $id
     * @param string $conceptName
     * @return \Ampersand\Interfacing\Resource
     */
    public static function makeResource(string $id, string $conceptName): Resource
    {
        return new Resource($id, Concept::getConcept($conceptName), InterfaceObjectFactory::getNullObject(), null);
    }

    /**
     * Factory function for new resource object
     *
     * @param string $conceptName
     * @return \Ampersand\Interfacing\Resource
     */
    public static function makeNewResource(string $conceptName): Resource
    {
        try {
            $concept = Concept::getConcept($conceptName);
        } catch (Exception $e) {
            throw new Exception("Resource type not found", 404);
        }
        
        if (!$concept->isObject() || $concept->isSession()) {
            throw new Exception("Resource type not found", 404); // Prevent users to instantiate resources of scalar type or SESSION
        }
        
        return new Resource($concept->createNewAtomId(), $concept, InterfaceObjectFactory::getNullObject(), null);
    }

    /**
     * Factory function to create a Resource object using an Atom object
     *
     * @param \Ampersand\Core\Atom $atom
     * @return \Ampersand\Interfacing\Resource
     */
    public static function makeResourceFromAtom(Atom $atom): Resource
    {
        return new Resource($atom->id, $atom->concept, InterfaceObjectFactory::getNullObject(), null);
    }
}
