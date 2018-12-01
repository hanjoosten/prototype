<?php
/* This file defines the (php) function 'TransitiveClosure', that computes the transitive closure of a relation.

   Suppose you have a relation r :: C * C, and that you need the transitive closure r+ of that relation.
   Since r* is not supported in the prototype generator as is, we need a way to instruct the ExecEngine
   to populate a relation rPlus :: C * C that contains the same population as r+
   Maintaining the population of rPlus correctly is not trivial, particularly when r is depopulated.
   The easiest way around this is to compute rPlus from scratch (using Warshall's algorithm).
   However, you then need to know that r is being (de)populated, so we need a copy of r.

   This leads to the following pattern:

   relation :: Concept*Concept
   relationCopy :: Concept*Concept -- copied value of 'relation' allows for detecting modifcation events
   relationStar :: Concept*Concept -- transitive closure of relation

   ROLE ExecEngine MAINTAINS "relationCompTransitiveClosure"
   RULE "relationCompTransitiveClosure": relation = relationCopy
   VIOLATION (TXT "{EX} TransitiveClosure;relation;Concept;relationCopy;relationStar")

   NOTES:
   1) The above example is made for ease of use. This is what you need to do:
      a) copy and paste the above example into your own ADL script;
      b) replace the names of 'relation' and 'Concept' (cases sensitive, also as part of a word) with what you need
   2) Of course, there are all sorts of alternative ways in which 'TransitiveClosure' can be used.
   3) There are ways to optimize the below code, e.g. by splitting the function into an 'InsTransitiveClosure'
      and a 'DelTransitiveClosure'
   4) Rather than defining/computing rStar (for r*), you may use the expression (I \/ rPlus)
*/

use Ampersand\Core\Concept;
use Ampersand\Core\Relation;
use Ampersand\Rule\ExecEngine;
use Ampersand\Core\Link;

ExecEngine::registerFunction('TransitiveClosure', function ($r, $C, $rCopy, $rPlus) {
    if (func_num_args() != 4) {
        throw new Exception("Wrong number of arguments supplied for function TransitiveClosure(): ".func_num_args()." arguments", 500);
    }
    
    // Check and return if this function for a particular rule is already executed in an exec-engine run
    $warshallRunCount = $GLOBALS['ext']['ExecEngine']['functions']['warshall']['runCount'];
    $execEngineRunCount = ExecEngine::$runCount;
    if ($GLOBALS['ext']['ExecEngine']['functions']['warshall']['warshallRuleChecked'][$r]) {
        if ($warshallRunCount == $execEngineRunCount) {
            return;
        }
    }
    $GLOBALS['ext']['ExecEngine']['functions']['warshall']['warshallRuleChecked'][$r] = true;
    $GLOBALS['ext']['ExecEngine']['functions']['warshall']['runCount'] = ExecEngine::$runCount;

    // Get concept and relation objects
    $concept = Concept::getConceptByLabel($C);
    $relationR = Relation::getRelation($r, $concept, $concept);
    $relationRCopy = Relation::getRelation($rCopy, $concept, $concept);
    $relationRPlus = Relation::getRelation($rPlus, $concept, $concept);

    // Empty rCopy and rPlus
    $relationRCopy->deleteAllLinks();
    $relationRPlus->deleteAllLinks();

    // Get adjacency matrix
    $closure = [];
    $atoms = [];
    foreach ($relationR->getAllLinks() as $link) {
        /** @var \Ampersand\Core\Link $link */
        $closure[$link->src()][$link->tgt()] = true;
        $atoms[] = $link->src();
        $atoms[] = $link->tgt();
        
        // Store a copy in rCopy relation
        (new Link($relationRCopy, $link->src(), $link->tgt()))->add();
    }
    $atoms = array_unique($atoms);
    
    // Compute transitive closure following Warshall's algorithm
    foreach ($atoms as $k) {
        foreach ($atoms as $i) {
            if ($closure[$i][$k]) {
                foreach ($atoms as $j) {
                    if ($closure[$i][$j] || $closure[$k][$j]) {
                        // Write to rPlus
                        (new Link($relationRPlus, $i, $j))->add();
                    }
                }
            }
        }
    }
});
