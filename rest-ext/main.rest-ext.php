<?php

// PHP Data Model definition file

// WARNING - WARNING - WARNING
// DO NOT EDIT THIS FILE (unless you know what you are doing)
//
// If you use supply a datamodel.xxxx.xml file with your module
// the this file WILL BE overwritten by the compilation of the
// module (during the setup) if the datamodel.xxxx.xml file
// contains the definition of new classes or menus.
//
// The recommended way to define new classes (for iTop 2.0) is via the XML definition.
// This file remains in the module's template only for the cases where there is:
// - either no new class or menu defined in the XML file
// - or no XML file at all supplied by the module

/**
 * Implementation of custom REST services (get_related: support custom show_fields)
 *  
 *  custom api: ext/get_related
 *  $class: mandatory, class name
 *  $key: mandatory, search object
 *  $relation: impacts or depends on
 *  $optional: optional, an array with keys:filter,show_relations,output_fields,depth,direction,redundancy
 *      - filter: array of class name, like array("Person","Server"). only show objects in filter array
 *      - show_relations: array of class name, like array("Person", "Server"). only show relations about class in the array
 *      - hide_relations: array of class name, like array("Person", "Server"). hide relation with class in the array 
 *      - output_fields: array like array("classname"=>"fields")
 *      - depth: relation depth
 *      - direction: impacts direction(up or down)
 *      - redundancy: true of false
	public function extRelated($class, $query, $relation="impacts", $optional=array())
	{
		$mandatory = array('class'=>$class, 'key'=>$query, 'relation'=>$relation);
		$param = array_merge($mandatory, $optional);
		return $this->operation('ext/get_related', $param);
	}
	
*/

 
class CustomExtServices implements iRestServiceProvider
{
	public function ListOperations($sVersion)
	{
		$aOps = array();
		if (in_array($sVersion, array('1.0', '1.1', '1.2', '1.3')))
		{
			$aOps[] = array(
				'verb' => 'ext/get_related',
				'description' => 'extend core/get_related: support custom filter of object and relation output'
			);
		}
		return $aOps;
	}
		
	public function MergeGraph(RelationGraph $down, RelationGraph $up)
	{
		$oIter1 = new RelationTypeIterator($up, 'Node');
		foreach($oIter1 as $oNode)
		{
			try{
				$down->_AddNode($oNode);
			}catch(Exception $e){
				continue;
			}
		}
		$oIter2 = new RelationTypeIterator($up, 'Edge');
		foreach($oIter2 as $oEdge)
		{
			try{
				$down->_AddEdge($oEdge);
			}catch(ExecOperation $e){
				continue;
			}
		}
		return($down);
	}

	public function ExecOperation($sVersion, $sVerb, $aParams)
	{
		$oResult = new RestResultWithObjects();
		switch ($sVerb)
		{
		case 'ext/get_related':
			$oResult = new RestResultWithRelations();
			$sClass = RestUtils::GetClass($aParams, 'class');
			$key = RestUtils::GetMandatoryParam($aParams, 'key');
			$sRelation = RestUtils::GetMandatoryParam($aParams, 'relation');
			$iMaxRecursionDepth = RestUtils::GetOptionalParam($aParams, 'depth', 20 /* = MAX_RECURSION_DEPTH */);
			$sDirection = RestUtils::GetOptionalParam($aParams, 'direction', null);
			$bEnableRedundancy = RestUtils::GetOptionalParam($aParams, 'redundancy', false);

			// 用于relation只显示或者隐藏某些relation
			$aShowRelations = RestUtils::GetOptionalParam($aParams, 'show_relations', array());
			$aHideRelations = RestUtils::GetOptionalParam($aParams, 'hide_relations', array());
			
			// 过滤Object结果，默认只显示Person
			$sFilter = RestUtils::GetOptionalParam($aParams, 'filter', array("Person"));
			// output_fields 改为数组，支持传递多个类的output_fields("ClassName" => "name,friendlyname")
			$sShowFields = RestUtils::GetOptionalParam($aParams, 'output_fields', (object) array("Person" => "friendlyname,email,phone"));
			
			// $sShowFields是关联数组("key"=>"value"形式定义的数组)传参过来是stdClass
			if(!is_array($sFilter) || !is_object($sShowFields) || !is_array($aShowRelations) || !is_array($aHideRelations))
			{
				$oResult->code = RestResult::INTERNAL_ERROR;
				$oResult->message = "Invalid value: parameter 'filter, show_relations, hide_relations' should be indexed array. parameter output_fields should be Associative array('key'=>'value')";
				return $oResult;				
			}
			
			$aShowFields = array();
			$bExtendedOutput = array();
			foreach($sShowFields as $k => $v)
			{
				$aShowFields[$k] = RestUtils::GetFieldList($k, $sShowFields, $k);
				$bExtendedOutput[$k] = (RestUtils::GetOptionalParam($sShowFields, $k, '*') == '*+');
			}
			//$aShowFields = RestUtils::GetFieldList("Person", $aParams, 'output_fields');
			
			$bReverse = false;
			

			if (is_null($sDirection) && ($sRelation == 'depends on'))
			{
				// Legacy behavior, consider "depends on" as a forward relation
				$sRelation = 'impacts';
				$sDirection = 'up'; 
				$bReverse = true; // emulate the legacy behavior by returning the edges
			}
			else if(is_null($sDirection))
			{
				$sDirection = 'down';
			}
	
			$oObjectSet = RestUtils::GetObjectSetFromKey($sClass, $key);
			if ($sDirection == 'down')
			{
				$oRelationGraph = $oObjectSet->GetRelatedObjectsDown($sRelation, $iMaxRecursionDepth, $bEnableRedundancy);
			}
			else if ($sDirection == 'up')
			{
				$oRelationGraph = $oObjectSet->GetRelatedObjectsUp($sRelation, $iMaxRecursionDepth, $bEnableRedundancy);
			}
			else if ($sDirection == 'both')
			{
				$oRelationGraph_down = $oObjectSet->GetRelatedObjectsDown($sRelation, $iMaxRecursionDepth, $bEnableRedundancy);
				$oRelationGraph_up = $oObjectSet->GetRelatedObjectsUp($sRelation, $iMaxRecursionDepth, $bEnableRedundancy);	
				$oRelationGraph = $this->MergeGraph($oRelationGraph_down, $oRelationGraph_up);
			}
			else
			{
				$oResult->code = RestResult::INTERNAL_ERROR;
				$oResult->message = "Invalid value: '$sDirection' for the parameter 'direction'. Valid values are 'up' and 'down' and 'both'";
				return $oResult;				
			}
			
			if ($bEnableRedundancy)
			{
				// Remove the redundancy nodes from the output
				$oIterator = new RelationTypeIterator($oRelationGraph, 'Node');
				foreach($oIterator as $oNode)
				{
					if ($oNode instanceof RelationRedundancyNode)
					{
						$oRelationGraph->FilterNode($oNode);
					}
				}
			}
			
			$aIndexByClass = array();
			$oIterator = new RelationTypeIterator($oRelationGraph);
			foreach($oIterator as $oElement)
			{
				if ($oElement instanceof RelationObjectNode)
				{
					$oObject = $oElement->GetProperty('object');
					// 只取filter定义的类型
					if ($oObject && in_array(get_class($oObject), $sFilter))
					{
						$k = get_class($oObject);
						if(!isset($aShowFields[$k]))
						{
							$aShowFields[$k] = null;
						}
						if(!isset($bExtendedOutput[$k]))
						{
							$bExtendedOutput[$k] = false;
						}
						if ($bEnableRedundancy)
						{
							// Add only the "reached" objects
							if ($oElement->GetProperty('is_reached'))
							{
								$aIndexByClass[get_class($oObject)][$oObject->GetKey()] = null;
								$oResult->AddObject(0, '', $oObject, $aShowFields[$k], $bExtendedOutput[$k]);
							}
						}
						else
						{
							$aIndexByClass[get_class($oObject)][$oObject->GetKey()] = null;
							$oResult->AddObject(0, '', $oObject, $aShowFields[$k], $bExtendedOutput[$k]);
						}
					}
				}
				else if ($oElement instanceof RelationEdge)
				{
					$oSrcObj = $oElement->GetSourceNode()->GetProperty('object');
					$oDestObj = $oElement->GetSinkNode()->GetProperty('object');
					
					// 指定显示relation的Class, $aShowRelations为空时，不限制显示
					if($aShowRelations && (!in_array(get_class($oSrcObj), $aShowRelations) || !in_array(get_class($oDestObj), $aShowRelations)))
					{
						continue;
					}
					// 隐藏某些relation
					if(in_array(get_class($oSrcObj), $aHideRelations) || in_array(get_class($oDestObj), $aHideRelations))
					{
						continue;
					}
					$sSrcKey = get_class($oSrcObj) . '::' . $oSrcObj->GetKey() . '::' . $oSrcObj->Get('friendlyname');
					$sDestKey = get_class($oDestObj) . '::' . $oDestObj->GetKey() . '::' . $oDestObj->Get('friendlyname');
					
					if ($bEnableRedundancy)
					{
						// Add only the edges where both source and destination are "reached"
						if ($oElement->GetSourceNode()->GetProperty('is_reached') && $oElement->GetSinkNode()->GetProperty('is_reached'))
						{
							if ($bReverse)
							{
								$oResult->AddRelation($sDestKey, $sSrcKey);
							}
							else
							{
								$oResult->AddRelation($sSrcKey, $sDestKey);
							}
						}
					}
					else
					{
						if ($bReverse)
						{
							$oResult->AddRelation($sDestKey, $sSrcKey);
						}
						else
						{
							$oResult->AddRelation($sSrcKey, $sDestKey);
						}
					}
				}
			}

			if (count($aIndexByClass) > 0)
			{
				$aStats = array();
				$aUnauthorizedClasses = array();
				foreach ($aIndexByClass as $sClass => $aIds)
				{
					if (UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ) != UR_ALLOWED_YES)
					{
						$aUnauthorizedClasses[$sClass] = true;
					}
					$aStats[] = $sClass.'= '.count($aIds);
				}
				if (count($aUnauthorizedClasses) > 0)
				{
					$sClasses = implode(', ', array_keys($aUnauthorizedClasses));
					$oResult = new RestResult();
					$oResult->code = RestResult::UNAUTHORIZED;
					$oResult->message = "The current user does not have enough permissions for exporting data of class(es): $sClasses";
				}
				else
				{
					$oResult->message = "Scope: ".$oObjectSet->Count()."; Related objects: ".implode(', ', $aStats);
				}
			}
			else
			{
				$oResult->message = "Nothing found";
			}
			break;
		default:
		}
		return $oResult;
	}
}
