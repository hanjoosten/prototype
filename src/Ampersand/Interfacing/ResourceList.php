<?php

/*
 * This file is part of the Ampersand backend framework.
 *
 */

namespace Ampersand\Interfacing;
use stdClass;
use Exception;
use ArrayIterator;
use IteratorAggregate;
use Ampersand\Misc\Config;
use Ampersand\Core\Atom;
use Ampersand\Log\Logger;

/**
 *
 * @author Michiel Stornebrink (https://github.com/Michiel-s)
 *
 */
class ResourceList implements IteratorAggregate {
    
    /**
    *
    * @var \Psr\Log\LoggerInterface
    */
    protected $logger;
    
    /**
     * Source Atom/Resource of this Resourcelist
     * 
     * @var \Ampersand\Interfacing\Resource $src
     */
    private $src = null;
    
    /**
     * @var InterfaceObject $parentIfc [description]
     */
    private $parentIfc = null;
    
    /**
     * @var array $tgtResources list with target resources
     */
    private $tgtResources = null;
    
    
    public function __construct(Resource $src, InterfaceObject $parentIfc){
        /** @var \Pimple\Container $container */
        global $container;
        $this->logger = Logger::getLogger('INTERFACING');
        
        if($parentIfc->isRoot() && !$container['ampersand_app']->isAccessibleIfc($parentIfc)) throw new Exception("Unauthorized to access interface {$parentIfc->label}", 401); // 401: Unauthorized
        
        $this->src = $src;
        $this->ifc = $parentIfc;
    }
    
    /**
     * @return ArrayIterator
     */
    public function getIterator(){
        if($this->ifc->tgtConcept->isObject()) return new ArrayIterator($this->getTgtResources());
        else return new ArrayIterator($this->getTgtAtoms());
    }
    
    public function getSrc(){
        return $this->src;
    }
    
    /**
     * @return string
     */
    public function getPath(){
        return $this->src->getPath() . '/' . $this->ifc->id;
    }
    
    /**
     * @return InterfaceObject
     */
    public function getIfc(){
        return $this->ifc;
    }
    
    /**
     * @param string $tgtId
     * @return Resource
     */
    public function one($tgtId = null){
        // If no tgtId is provided, the srcId is used. Usefull for ident interface expressions (I[Concept])
        if(is_null($tgtId)) $tgtId = $this->src->id;
        
        $arr = $this->getTgtResources();
        
        if(!array_key_exists($tgtId, $arr)) throw new Exception ("Resource '{$resource}' not found", 404);
        
        return $arr[$tgtId];
    }
    
    /**
     * @param boolean $fromCache specifies if target resources may be get from cache (true) or recalculated (false)
     * @return Resource[]
     */
    private function getTgtResources($fromCache = true){
        if(!isset($this->tgtResources) || !$fromCache){
            $this->tgtResources = [];
            // If interface isIdent (i.e. expr = I[Concept]) we can return the src
            if($this->ifc->isIdent()){
                $this->tgtResources[$this->src->id] = $this->makeResource($this->src->id);
                
            // Else try to get tgt atom from src query data (in case of uni relation in same table)
            }else{
                $tgtId = $this->src->getQueryData('ifc_' . $this->ifc->id, $exists); // column is prefixed with ifc_ in query data
                if($exists){
                    if(!is_null($tgtId)) $this->tgtResources[$tgtId] = $this->makeResource($tgtId);
                }else{
                    foreach ($this->ifc->getIfcData($this->src) as $row) {
                        $r = $this->makeResource($row['tgt']);
                        $r->setQueryData($row);
                        $this->tgtResources[$r->id] = $r;
                    }
                }
            }
        }
        
        return $this->tgtResources;
    }
    
    
    /**
     * Codes below look similar to getTgtResources() function above, but returns list of Atoms instead of Resources
     * @return Atom[]
     */
    private function getTgtAtoms($fromCache = true){
        if(isset($this->tgtResources) && $fromCache) return $this->tgtResources;
        elseif($this->ifc->tgtConcept->isObject()) return $this->getTgtResources($fromCache);
        
        // Otherwise (i.e. non-object atoms) get atoms from database. This is never cached. We only cache resources (i.e. object atoms)
        else{
            $tgtAtoms = [];
            // Try to get tgt atom from src query data (in case of uni relation in same table)
            $tgt = $this->src->getQueryData('ifc_' . $this->ifc->id, $exists); // column is prefixed with ifc_ in query data
            if($exists){
                if(!is_null($tgt)) $tgtAtoms[] = new Atom($tgt, $this->ifc->tgtConcept);
            }else{
                foreach ($this->ifc->getIfcData($this->src) as $row) $tgtAtoms[] = new Atom($row['tgt'], $this->ifc->tgtConcept);
            }
            return $tgtAtoms;
        }
        
    }

    /**
     * Resource factory. Instantiates a new target resource
     *
     * @param string $tgtId
     * @return \Ampersand\Interfacing\Resource
     */
    protected function makeResource(string $tgtId): Resource {
        return new Resource($tgtId, $this->ifc->tgtConcept, $this);
    }

/**************************************************************************************************
 * Methods to call on ResourceList
 *************************************************************************************************/
     
    /**
     * @param int $rcOptions
     * @param int $ifcOptions
     * @param int $depth
     * @param array $recursionArr
     * @return mixed[]
     */
    public function get($rcOptions = Resource::DEFAULT_OPTIONS, $ifcOptions = InterfaceObject::DEFAULT_OPTIONS, int $depth = null, $recursionArr = []){
        $this->logger->debug("get() called for {$this->src} / {$this->ifc}");
        if(!$this->ifc->crudR()) throw new Exception ("Read not allowed for ". $this->ifc->getPath(), 405);
        
        // Initialize result
        $result = [];
        
        // Object nodes
        if($this->ifc->tgtConcept->isObject()){
            
            foreach ($this->getTgtResources() as $resource){
                $result[] = $resource->get($rcOptions, $ifcOptions, $depth, $recursionArr); // for json_encode $resource->jsonSerializable() is called
            }
            
            // Special case for leave PROP: return false when result is empty, otherwise true (i.e. I atom must be present)
            // Enables boolean functionality for editing ampersand property relations
            if($this->ifc->isLeaf() && $this->ifc->isProp()){
                if(empty($result)) return false;
                else return true;
            }
            
        // Non-object nodes (leaves, because subinterfaces are not allowed for non-objects)
        }else{
            foreach ($this->getTgtAtoms() as $atom) $result[] = $atom; // for json_encode $atom->jsonSerializable() is called
        }
        
        // Return result using UNI-aspect (univalent-> value/object, non-univalent -> list of values/objects)
        if($this->ifc->isUni() && empty($result)) return null;
        elseif($this->ifc->isUni()) return current($result);
        else return $result;
        
    }
    
    /**
     * @param stdClass $resourceToPost
     * @return Resource
     */
    public function post(stdClass $resourceToPost){
        if(!$this->ifc->crudC()) throw new Exception ("Create not allowed for ". $this->ifc->getPath(), 405);
        
        // Use attribute '_id_' if provided
        if(isset($resourceToPost->_id_)){
            $resource = $this->makeResource($resourceToPost->_id_);
            if($resource->exists()) throw new Exception ("Cannot create resource that already exists", 400);
        }else{
            $resource = $this->makeResource(null);
        }
        
        // If interface is editable, also add tuple(src, tgt) in interface relation
        if($this->ifc->isEditable() && $this->ifc->crudU()) $this->add($resource->id);
        else $resource->add();
        
        // Put resource attributes
        $resource->put($resourceToPost);
        
        // Special case for file upload. TODO: make extension with hooks
        if($this->ifc->tgtConcept->isFileObject()){
            if (is_uploaded_file($_FILES['file']['tmp_name'])){
                $tmp_name = $_FILES['file']['tmp_name'];
                $new_name = time() . '_' . $_FILES['file']['name'];
                $absolutePath = Config::get('absolutePath') . Config::get('uploadPath') . $new_name;
                $relativePath = Config::get('uploadPath') . $new_name;
                $result = move_uploaded_file($tmp_name, $absolutePath);
                 
                if($result) Logger::getUserLogger()->notice("File '{$new_name}' uploaded");
                else throw new Exception ("Error in file upload", 500);
                
                // Populate filePath and originalFileName relations in database
                $resource->link($relativePath, 'filePath[FileObject*FilePath]')->add();
                $resource->link($_FILES['file']['name'], 'originalFileName[FileObject*FileName]')->add();
            }else{
                throw new Exception ("No file uploaded", 500);
            }
        }
        
        return $resource;
    }
    
    /**
     * Update a complete resource list (updates only this subinterface, not any level(s) deeper for now)
     * @param mixed $value
     * @return boolean
     */
    public function put($value){
        
        if($this->ifc->isUni()){ // expect value to be object or literal
            if(is_array($value)) throw new Exception("Non-array expected but array provided while updating " . $this->ifc->getPath(), 400);
            
            if($this->ifc->tgtConcept->isObject()){ // expect value to be object or null
                if(!is_object($value) && !is_null($value)) throw new Exception("Object (or null) expected but " . gettype($value) . " provided while updating " . $this->ifc->getPath(), 400);
                
                if(is_null($value)) $this->set($value);
                elseif(isset($value->_id_)) $this->set($value->_id_);
                else throw new Exception("No object identifier (_id_) provided while updating " . $this->ifc->getPath(), 400);
                
            }else{ // expect value to be literal (i.e. non-object) or null
                $this->set($value);
            }
            
        }else{ // expect value to be array
            if(!is_array($value)) throw new Exception("Array expected but not provided while updating " . $this->ifc->getPath(), 400);
            
            // First empty existing list
            $this->removeAll();
            
            // Add provided values
            foreach($value as $item){
                if($this->ifc->tgtConcept->isObject()){ // expect item to be object
                    if(!is_object($item)) throw new Exception("Object expected but " . gettype($item) . " provided while updating " . $this->ifc->getPath(), 400);
                    
                    if(isset($item->_id_)) $this->add($item->_id_);
                    else throw new Exception("No object identifier (_id_) provided while updating " . $this->ifc->getPath(), 400);
                }else{ // expect item to be literal (i.e. non-object) or null
                    $this->add($item);
                }
            }
        }
        
        return true;
    }
    
    /**
     * Alias of set() method. Used by Resource::patch() method
     * @param string $value
     * @return boolean
     */
    public function replace($value){
        if(!$this->ifc->isUni()) throw new Exception ("Cannot use replace for non-univalent interface " . $this->ifc->getPath() . ". Use add or remove instead", 400);
        return $this->set($value);
    }
    
    /**
     * Set provided value (for univalent interfaces)
     * @param string $value (value null is supported)
     * @return boolean
     */
    public function set($value){
        if(!$this->ifc->isUni()) throw new Exception ("Cannot use set() for non-univalent interface " . $this->ifc->getPath() . ". Use add or remove instead", 400);
        
        // Handle Ampersand properties [PROP]
        if($this->ifc->isProp()){
            if($value === true) $this->add($this->src->id);
            elseif ($value === false) $this->remove($this->src->id);
            else throw new Exception ("Boolean expected, non-boolean provided.", 400);
        }else{
            if(is_null($value)) $this->removeAll();
            else $this->add($value);
        }
        
        return true;
    }
    
    /**
     * Add value to resource list
     * @param string $value
     * @return boolean
     */
    public function add($value){
        if(!isset($value)) throw new Exception ("Cannot add item. Value not provided", 400);
        if(is_object($value) || is_array($value)) throw new Exception("Literal expected but " . gettype($value) . " provided while updating " . $this->ifc->getPath(), 400);
        
        if(!$this->ifc->isEditable()) throw new Exception ("Interface is not editable " . $this->ifc->getPath(), 405);
        if(!$this->ifc->crudU()) throw new Exception ("Update not allowed for " . $this->ifc->getPath(), 405);
        
        $tgt = new Atom($value, $this->ifc->tgtConcept);
        if($tgt->concept->isObject() && !$this->ifc->crudC() && !$tgt->exists()) throw new Exception ("Create not allowed for " . $this->ifc->getPath(), 405);
        
        $tgt->add();
        $this->src->link($tgt, $this->ifc->relation(), $this->ifc->relationIsFlipped)->add();
        
        return true;
    }
    
    /**
     * Remove value from resource list
     * @param string $value
     * @return boolean
     */
    public function remove($value){
        if(!isset($value)) throw new Exception ("Cannot remove item. Value not provided", 400);
        if(is_object($value) || is_array($value)) throw new Exception("Literal expected but " . gettype($value) . " provided while updating " . $this->ifc->getPath(), 400);
        
        if(!$this->ifc->isEditable()) throw new Exception ("Interface is not editable " . $this->ifc->getPath(), 405);
        if(!$this->ifc->crudU()) throw new Exception ("Update not allowed for " . $this->ifc->getPath(), 405);
        
        $tgt = new Atom($value, $this->ifc->tgtConcept);
        $this->src->link($tgt, $this->ifc->relation(), $this->ifc->relationIsFlipped)->delete();
        
        return true;
    }
    
    public function removeAll(){
        if(!$this->ifc->isEditable()) throw new Exception ("Interface is not editable " . $this->ifc->getPath(), 405);
        if(!$this->ifc->crudU()) throw new Exception ("Update not allowed for " . $this->ifc->getPath(), 405);
        
        foreach ($this->getTgtAtoms() as $tgt) {
            $this->src->link($tgt, $this->ifc->relation(), $this->ifc->relationIsFlipped)->delete();
        }
    }
    
}
