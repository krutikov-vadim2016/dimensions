<?php
	use Bitrix\Main,
		Bitrix\Iblock,
		Bitrix\Catalog;

	IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/iblock/classes/general/cml2.php');

	class CIBlockCMLImportCustom extends CIBlockCMLImport
	{
		function ImportElement($arXMLElement, &$counter, $bWF, $arParent)
		{
			global $USER;
			$USER_ID = is_object($USER)? intval($USER->GetID()): 0;
			$arElement = array(
				"ACTIVE" => "Y",
				"PROPERTY_VALUES" => array(),
			);

			if(isset($arXMLElement[$this->mess["IBLOCK_XML2_VERSION"]]))
				$arElement["TMP_ID"] = $arXMLElement[$this->mess["IBLOCK_XML2_VERSION"]];
			else
				$arElement["TMP_ID"] = $this->GetElementCRC($arXMLElement);

			if(isset($arXMLElement[$this->mess["IBLOCK_XML2_ID_1C_SITE"]]))
				$arElement["XML_ID"] = $arXMLElement[$this->mess["IBLOCK_XML2_ID_1C_SITE"]];
			elseif(isset($arXMLElement[$this->mess["IBLOCK_XML2_ID"]]))
				$arElement["XML_ID"] = $arXMLElement[$this->mess["IBLOCK_XML2_ID"]];

			$obElement = new CIBlockElement;
			$obElement->CancelWFSetMove();
			$rsElement = $obElement->GetList(
				Array("ID"=>"asc"),
				Array("=XML_ID" => $arElement["XML_ID"], "IBLOCK_ID" => $this->next_step["IBLOCK_ID"]),
				false, false,
				Array("ID", "TMP_ID", "ACTIVE", "CODE", "PREVIEW_PICTURE", "DETAIL_PICTURE")
			);

			$bMatch = false;
			if($arDBElement = $rsElement->Fetch())
				$bMatch = ($arElement["TMP_ID"] == $arDBElement["TMP_ID"]);

			if($bMatch && $this->use_crc)
			{
				//Check Active flag in XML is not set to false
				if($this->CheckIfElementIsActive($arXMLElement))
				{
					//In case element is not active in database we have to activate it and its offers
					if($arDBElement["ACTIVE"] != "Y")
					{
						$obElement->Update($arDBElement["ID"], array("ACTIVE"=>"Y"), $bWF);
						$this->ChangeOffersStatus($arDBElement["ID"], "Y", $bWF);
						$counter["UPD"]++;
					}
				}
				$arElement["ID"] = $arDBElement["ID"];
			}
			elseif(isset($arXMLElement[$this->mess["IBLOCK_XML2_NAME"]]))
			{
				if($arDBElement)
				{
					if ($arDBElement["PREVIEW_PICTURE"] > 0)
						$this->arElementFilesId["PREVIEW_PICTURE"] = array($arDBElement["PREVIEW_PICTURE"]);
					if ($arDBElement["DETAIL_PICTURE"] > 0)
						$this->arElementFilesId["DETAIL_PICTURE"] = array($arDBElement["DETAIL_PICTURE"]);

					$rsProperties = $obElement->GetProperty($this->next_step["IBLOCK_ID"], $arDBElement["ID"], "sort", "asc");
					while($arProperty = $rsProperties->Fetch())
					{
						if(!array_key_exists($arProperty["ID"], $arElement["PROPERTY_VALUES"]))
							$arElement["PROPERTY_VALUES"][$arProperty["ID"]] = array(
								"bOld" => true,
							);

						$arElement["PROPERTY_VALUES"][$arProperty["ID"]][$arProperty['PROPERTY_VALUE_ID']] = array(
							"VALUE"=>$arProperty['VALUE'],
							"DESCRIPTION"=>$arProperty["DESCRIPTION"]
						);

						if($arProperty["PROPERTY_TYPE"] == "F" && $arProperty["VALUE"] > 0)
							$this->arElementFilesId[$arProperty["ID"]][] = $arProperty["VALUE"];
					}
				}

				if($this->bCatalog && $this->next_step["bOffer"])
				{
					$p = strpos($arXMLElement[$this->mess["IBLOCK_XML2_ID"]], "#");
					if($p !== false)
						$link_xml_id = substr($arXMLElement[$this->mess["IBLOCK_XML2_ID"]], 0, $p);
					else
						$link_xml_id = $arXMLElement[$this->mess["IBLOCK_XML2_ID"]];
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_LINK"]] = array(
						"n0" => array(
							"VALUE" => $this->GetElementByXML_ID($this->arProperties[$this->PROPERTY_MAP["CML2_LINK"]]["LINK_IBLOCK_ID"], $link_xml_id),
							"DESCRIPTION" => false,
						),
					);
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_NAME"]]))
					$arElement["NAME"] = $arXMLElement[$this->mess["IBLOCK_XML2_NAME"]];

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_DELETE_MARK"]]))
				{
					$value = $arXMLElement[$this->mess["IBLOCK_XML2_DELETE_MARK"]];
					$arElement["ACTIVE"] = ($value=="true") || intval($value)? "N": "Y";
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_BX_TAGS"], $arXMLElement))
					$arElement["TAGS"] = $arXMLElement[$this->mess["IBLOCK_XML2_BX_TAGS"]];

				if(array_key_exists($this->mess["IBLOCK_XML2_DESCRIPTION"], $arXMLElement))
				{
					if(strlen($arXMLElement[$this->mess["IBLOCK_XML2_DESCRIPTION"]]) > 0)
						$arElement["DETAIL_TEXT"] = $arXMLElement[$this->mess["IBLOCK_XML2_DESCRIPTION"]];
					else
						$arElement["DETAIL_TEXT"] = "";

					if(preg_match('/<[a-zA-Z0-9]+.*?>/', $arElement["DETAIL_TEXT"]))
						$arElement["DETAIL_TEXT_TYPE"] = "html";
					else
						$arElement["DETAIL_TEXT_TYPE"] = "text";
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_FULL_TITLE"], $arXMLElement))
				{
					if(strlen($arXMLElement[$this->mess["IBLOCK_XML2_FULL_TITLE"]]) > 0)
						$arElement["PREVIEW_TEXT"] = $arXMLElement[$this->mess["IBLOCK_XML2_FULL_TITLE"]];
					else
						$arElement["PREVIEW_TEXT"] = "";

					if(preg_match('/<[a-zA-Z0-9]+.*?>/', $arElement["PREVIEW_TEXT"]))
						$arElement["PREVIEW_TEXT_TYPE"] = "html";
					else
						$arElement["PREVIEW_TEXT_TYPE"] = "text";
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_INHERITED_TEMPLATES"], $arXMLElement))
				{
					$arElement["IPROPERTY_TEMPLATES"] = array();
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_INHERITED_TEMPLATES"]] as $TEMPLATE)
					{
						$id = $TEMPLATE[$this->mess["IBLOCK_XML2_ID"]];
						$template = $TEMPLATE[$this->mess["IBLOCK_XML2_VALUE"]];
						if(strlen($id) > 0 && strlen($template) > 0)
							$arElement["IPROPERTY_TEMPLATES"][$id] = $template;
					}
				}
				if(array_key_exists($this->mess["IBLOCK_XML2_BAR_CODE2"], $arXMLElement))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BAR_CODE"]] = array(
						"n0" => array(
							"VALUE" => $arXMLElement[$this->mess["IBLOCK_XML2_BAR_CODE2"]],
							"DESCRIPTION" => false,
						),
					);
				}
				elseif(array_key_exists($this->mess["IBLOCK_XML2_BAR_CODE"], $arXMLElement))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BAR_CODE"]] = array(
						"n0" => array(
							"VALUE" => $arXMLElement[$this->mess["IBLOCK_XML2_BAR_CODE"]],
							"DESCRIPTION" => false,
						),
					);
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_ARTICLE"], $arXMLElement))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_ARTICLE"]] = array(
						"n0" => array(
							"VALUE" => $arXMLElement[$this->mess["IBLOCK_XML2_ARTICLE"]],
							"DESCRIPTION" => false,
						),
					);
				}

				if(
					array_key_exists($this->mess["IBLOCK_XML2_MANUFACTURER"], $arXMLElement)
					&& $this->PROPERTY_MAP["CML2_MANUFACTURER"] > 0
				)
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_MANUFACTURER"]] = array(
						"n0" => array(
							"VALUE" => $this->CheckManufacturer($arXMLElement[$this->mess["IBLOCK_XML2_MANUFACTURER"]]),
							"DESCRIPTION" => false,
						),
					);
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_PICTURE"], $arXMLElement))
				{
					$rsFiles = $this->_xml_file->GetList(
						array("ID" => "asc"),
						array("PARENT_ID" => $arParent["ID"], "NAME" => $this->mess["IBLOCK_XML2_PICTURE"])
					);
					$arFile = $rsFiles->Fetch();
					if($arFile)
					{
						$description = "";
						if(strlen($arFile["ATTRIBUTES"]))
						{
							$arAttributes = unserialize($arFile["ATTRIBUTES"]);
							if(is_array($arAttributes) && array_key_exists($this->mess["IBLOCK_XML2_DESCRIPTION"], $arAttributes))
								$description = $arAttributes[$this->mess["IBLOCK_XML2_DESCRIPTION"]];
						}

						if(strlen($arFile["VALUE"]) > 0)
						{
							$arElement["DETAIL_PICTURE"] = $this->ResizePicture($arFile["VALUE"], $this->detail, "DETAIL_PICTURE", $this->PROPERTY_MAP["CML2_PICTURES"]);

							if(is_array($arElement["DETAIL_PICTURE"]))
							{
								$arElement["DETAIL_PICTURE"]["description"] = $description;
								$this->arFileDescriptionsMap[$arFile["VALUE"]][] = &$arElement["DETAIL_PICTURE"]["description"];
							}

							if(is_array($this->preview))
							{
								$arElement["PREVIEW_PICTURE"] = $this->ResizePicture($arFile["VALUE"], $this->preview, "PREVIEW_PICTURE");
								if(is_array($arElement["PREVIEW_PICTURE"]))
								{
									$arElement["PREVIEW_PICTURE"]["description"] = $description;
									$this->arFileDescriptionsMap[$arFile["VALUE"]][] = &$arElement["PREVIEW_PICTURE"]["description"];
								}
							}
						}
						else
						{
							$arElement["DETAIL_PICTURE"] = $this->MakeFileArray($this->_xml_file->GetAllChildrenArray($arFile["ID"]));

							if(is_array($arElement["DETAIL_PICTURE"]))
							{
								$arElement["DETAIL_PICTURE"]["description"] = $description;
							}
						}

						$prop_id = $this->PROPERTY_MAP["CML2_PICTURES"];
						if($prop_id > 0)
						{
							$i = 1;
							while($arFile = $rsFiles->Fetch())
							{
								$description = "";
								if(strlen($arFile["ATTRIBUTES"]))
								{
									$arAttributes = unserialize($arFile["ATTRIBUTES"]);
									if(is_array($arAttributes) && array_key_exists($this->mess["IBLOCK_XML2_DESCRIPTION"], $arAttributes))
										$description = $arAttributes[$this->mess["IBLOCK_XML2_DESCRIPTION"]];
								}

								if(strlen($arFile["VALUE"]) > 0)
									$arPropFile = $this->ResizePicture($arFile["VALUE"], $this->detail, $this->PROPERTY_MAP["CML2_PICTURES"], "DETAIL_PICTURE");
								else
									$arPropFile = $this->MakeFileArray($this->_xml_file->GetAllChildrenArray($arFile["ID"]));

								if(is_array($arPropFile))
								{
									$arPropFile = array(
										"VALUE" => $arPropFile,
										"DESCRIPTION" => $description,
									);
								}
								$arElement["PROPERTY_VALUES"][$prop_id]["n".$i] = $arPropFile;
								if (strlen($arFile["VALUE"]) > 0)
									$this->arFileDescriptionsMap[$arFile["VALUE"]][] = &$arElement["PROPERTY_VALUES"][$prop_id]["n".$i]["DESCRIPTION"];
								$i++;
							}

							if(is_array($arElement["PROPERTY_VALUES"][$prop_id]))
							{
								foreach($arElement["PROPERTY_VALUES"][$prop_id] as $PROPERTY_VALUE_ID => $PROPERTY_VALUE)
								{
									if(!$PROPERTY_VALUE_ID)
										unset($arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID]);
									elseif(substr($PROPERTY_VALUE_ID, 0, 1)!=="n")
										$arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID] = array(
											"tmp_name" => "",
											"del" => "Y",
										);
								}
								unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
							}
						}
					}
				}

				$cleanCml2FilesProperty = false;
				if(
					array_key_exists($this->mess["IBLOCK_XML2_FILE"], $arXMLElement)
					&& strlen($this->PROPERTY_MAP["CML2_FILES"]) > 0
				)
				{
					$prop_id = $this->PROPERTY_MAP["CML2_FILES"];
					$rsFiles = $this->_xml_file->GetList(
						array("ID" => "asc"),
						array("PARENT_ID" => $arParent["ID"], "NAME" => $this->mess["IBLOCK_XML2_FILE"])
					);
					$i = 1;
					while($arFile = $rsFiles->Fetch())
					{

						if(strlen($arFile["VALUE"]) > 0)
							$file = $this->MakeFileArray($arFile["VALUE"], array($prop_id));
						else
							$file = $this->MakeFileArray($this->_xml_file->GetAllChildrenArray($arFile["ID"]));

						$arElement["PROPERTY_VALUES"][$prop_id]["n".$i] = array(
							"VALUE" => $file,
							"DESCRIPTION" => $file["description"],
						);
						if(strlen($arFile["ATTRIBUTES"]))
						{
							$desc = unserialize($arFile["ATTRIBUTES"]);
							if(is_array($desc) && array_key_exists($this->mess["IBLOCK_XML2_DESCRIPTION"], $desc))
								$arElement["PROPERTY_VALUES"][$prop_id]["n".$i]["DESCRIPTION"] = $desc[$this->mess["IBLOCK_XML2_DESCRIPTION"]];
						}
						$i++;
					}
					$cleanCml2FilesProperty = true;
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_GROUPS"]]))
				{
					$arElement["IBLOCK_SECTION"] = array();
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_GROUPS"]] as $value)
					{
						if(array_key_exists($value, $this->SECTION_MAP))
							$arElement["IBLOCK_SECTION"][] = $this->SECTION_MAP[$value];
					}
					if($arElement["IBLOCK_SECTION"])
						$arElement["IBLOCK_SECTION_ID"] = $arElement["IBLOCK_SECTION"][0];
				}

				if(array_key_exists($this->mess["IBLOCK_XML2_PRICES"], $arXMLElement))
				{//Collect price information for future use
					$arElement["PRICES"] = array();
					if (is_array($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]]))
					{
						foreach($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]] as $price)
						{
							if(isset($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]]) && array_key_exists($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]], $this->PRICES_MAP))
							{
								$price["PRICE"] = $this->PRICES_MAP[$price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]]];
								$arElement["PRICES"][] = $price;
							}
						}
					}

					$arElement["DISCOUNTS"] = array();
					if(isset($arXMLElement[$this->mess["IBLOCK_XML2_DISCOUNTS"]]))
					{
						foreach($arXMLElement[$this->mess["IBLOCK_XML2_DISCOUNTS"]] as $discount)
						{
							if(
								isset($discount[$this->mess["IBLOCK_XML2_DISCOUNT_CONDITION"]])
								&& $discount[$this->mess["IBLOCK_XML2_DISCOUNT_CONDITION"]]===$this->mess["IBLOCK_XML2_DISCOUNT_COND_VOLUME"]
							)
							{
								$discount_value = $this->ToInt($discount[$this->mess["IBLOCK_XML2_DISCOUNT_COND_VALUE"]]);
								$discount_percent = $this->ToFloat($discount[$this->mess["IBLOCK_XML2_DISCOUNT_COND_PERCENT"]]);
								if($discount_value > 0 && $discount_percent > 0)
									$arElement["DISCOUNTS"][$discount_value] = $discount_percent;
							}
						}
					}
				}

				if($this->bCatalog && array_key_exists($this->mess["IBLOCK_XML2_AMOUNT"], $arXMLElement))
				{
					$arElement["QUANTITY_RESERVED"] = 0;
					if($arDBElement["ID"])
					{
						$iterator = Catalog\Model\Product::getList([
							'select' => ['ID', 'QUANTITY_RESERVED'],
							'filter' => ['=ID' => $arDBElement['ID']]
						]);
						$arElementTmp = $iterator->fetch();
						if (isset($arElementTmp["QUANTITY_RESERVED"]))
							$arElement["QUANTITY_RESERVED"] = (float)$arElementTmp["QUANTITY_RESERVED"];
						unset($arElementTmp);
						unset($iterator);
					}
					$arElement["QUANTITY"] = $this->ToFloat($arXMLElement[$this->mess["IBLOCK_XML2_AMOUNT"]]) - $arElement["QUANTITY_RESERVED"];
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_ITEM_ATTRIBUTES"]]))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_ATTRIBUTES"]] = array();
					$i = 0;
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_ITEM_ATTRIBUTES"]] as $value)
					{
						$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_ATTRIBUTES"]]["n".$i] = array(
							"VALUE" => $value[$this->mess["IBLOCK_XML2_VALUE"]],
							"DESCRIPTION" => $value[$this->mess["IBLOCK_XML2_NAME"]],
						);
						$i++;
					}
				}

				$i = 0;
				$weightKey = false;
				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_TRAITS_VALUES"]]))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]] = array();
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_TRAITS_VALUES"]] as $value)
					{
						if(
							!array_key_exists("PREVIEW_TEXT", $arElement)
							&& $value[$this->mess["IBLOCK_XML2_NAME"]] == $this->mess["IBLOCK_XML2_FULL_TITLE2"]
						)
						{
							$arElement["PREVIEW_TEXT"] = $value[$this->mess["IBLOCK_XML2_VALUE"]];
							if(strpos($arElement["PREVIEW_TEXT"], "<")!==false)
								$arElement["PREVIEW_TEXT_TYPE"] = "html";
							else
								$arElement["PREVIEW_TEXT_TYPE"] = "text";
						}
						elseif(
							$value[$this->mess["IBLOCK_XML2_NAME"]] == $this->mess["IBLOCK_XML2_HTML_DESCRIPTION"]
						)
						{
							if(strlen($value[$this->mess["IBLOCK_XML2_VALUE"]]) > 0)
							{
								$arElement["DETAIL_TEXT"] = $value[$this->mess["IBLOCK_XML2_VALUE"]];
								$arElement["DETAIL_TEXT_TYPE"] = "html";
							}
						}
						elseif(
							$value[$this->mess["IBLOCK_XML2_NAME"]] == $this->mess["IBLOCK_XML2_FILE"]
						)
						{
							if(strlen($value[$this->mess["IBLOCK_XML2_VALUE"]]) > 0)
							{
								$prop_id = $this->PROPERTY_MAP["CML2_FILES"];

								$j = 1;
								while (isset($arElement["PROPERTY_VALUES"][$prop_id]["n".$j]))
									$j++;

								$file = $this->MakeFileArray($value[$this->mess["IBLOCK_XML2_VALUE"]], array($prop_id));
								if (is_array($file))
								{
									$arElement["PROPERTY_VALUES"][$prop_id]["n".$j] = array(
										"VALUE" => $file,
										"DESCRIPTION" => "",
									);
									unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
									$this->arFileDescriptionsMap[$value[$this->mess["IBLOCK_XML2_VALUE"]]][] = &$arElement["PROPERTY_VALUES"][$prop_id]["n".$j]["DESCRIPTION"];
									$cleanCml2FilesProperty = true;
								}
							}
						}
						elseif(
							$value[$this->mess["IBLOCK_XML2_NAME"]] == $this->mess["IBLOCK_XML2_FILE_DESCRIPTION"]
						)
						{
							if(strlen($value[$this->mess["IBLOCK_XML2_VALUE"]]) > 0)
							{
								list($fileName, $description) = explode("#", $value[$this->mess["IBLOCK_XML2_VALUE"]]);
								if (isset($this->arFileDescriptionsMap[$fileName]))
								{
									foreach($this->arFileDescriptionsMap[$fileName] as $k => $tmp)
										$this->arFileDescriptionsMap[$fileName][$k] = $description;
								}
							}
						}
						else
						{
							if($value[$this->mess["IBLOCK_XML2_NAME"]] == $this->mess["IBLOCK_XML2_WEIGHT"])
							{
								$arElement["BASE_WEIGHT"] = $this->ToFloat($value[$this->mess["IBLOCK_XML2_VALUE"]])*1000;
								$weightKey = "n".$i;
							}

							$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]]["n".$i] = array(
								"VALUE" => $value[$this->mess["IBLOCK_XML2_VALUE"]],
								"DESCRIPTION" => $value[$this->mess["IBLOCK_XML2_NAME"]],
							);
							$i++;
						}
					}
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_WEIGHT"]]))
				{
					if ($weightKey !== false)
					{
					}
					elseif (!isset($arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]]))
					{
						$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]] = array();
						$weightKey = "n0";
					}
					else // $weightKey === false && isset($arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]])
					{
						$weightKey = "n".$i;
					}
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TRAITS"]][$weightKey] = array(
						"VALUE" => $arXMLElement[$this->mess["IBLOCK_XML2_WEIGHT"]],
						"DESCRIPTION" => $this->mess["IBLOCK_XML2_WEIGHT"],
					);
					$arElement["BASE_WEIGHT"] = $this->ToFloat($arXMLElement[$this->mess["IBLOCK_XML2_WEIGHT"]])*1000;
				}

				if ($cleanCml2FilesProperty)
				{
					$prop_id = $this->PROPERTY_MAP["CML2_FILES"];
					if(is_array($arElement["PROPERTY_VALUES"][$prop_id]))
					{
						foreach($arElement["PROPERTY_VALUES"][$prop_id] as $PROPERTY_VALUE_ID => $PROPERTY_VALUE)
						{
							if(!$PROPERTY_VALUE_ID)
								unset($arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID]);
							elseif(substr($PROPERTY_VALUE_ID, 0, 1)!=="n")
								$arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID] = array(
									"tmp_name" => "",
									"del" => "Y",
								);
						}
						unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
					}
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_TAXES_VALUES"]]))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TAXES"]] = array();
					$i = 0;
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_TAXES_VALUES"]] as $value)
					{
						$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_TAXES"]]["n".$i] = array(
							"VALUE" => $value[$this->mess["IBLOCK_XML2_TAX_VALUE"]],
							"DESCRIPTION" => $value[$this->mess["IBLOCK_XML2_NAME"]],
						);
						$i++;
					}
				}

				$rsBaseUnit = $this->_xml_file->GetList(
					array("ID" => "asc"),
					array(
						"><LEFT_MARGIN" => array($arParent["LEFT_MARGIN"], $arParent["RIGHT_MARGIN"]),
						"NAME" => $this->mess["IBLOCK_XML2_BASE_UNIT"],
					),
					array("ID", "ATTRIBUTES")
				);
				while ($arBaseUnit = $rsBaseUnit->Fetch())
				{
					if(strlen($arBaseUnit["ATTRIBUTES"]) > 0)
					{
						$info = unserialize($arBaseUnit["ATTRIBUTES"]);
						if(
							is_array($info)
							&& array_key_exists($this->mess["IBLOCK_XML2_CODE"], $info)
						)
						{
							$arXMLElement[$this->mess["IBLOCK_XML2_BASE_UNIT"]] = $info[$this->mess["IBLOCK_XML2_CODE"]];
						}
					}
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_BASE_UNIT"]]))
				{
					$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BASE_UNIT"]] = array(
						"n0" => $this->convertBaseUnitFromXmlToPropertyValue($arXMLElement[$this->mess["IBLOCK_XML2_BASE_UNIT"]]),
					);
				}

				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_PROPERTIES_VALUES"]]))
				{
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_PROPERTIES_VALUES"]] as $value)
					{
						if(!array_key_exists($this->mess["IBLOCK_XML2_ID"], $value))
							continue;

						$prop_id = $value[$this->mess["IBLOCK_XML2_ID"]];
						unset($value[$this->mess["IBLOCK_XML2_ID"]]);

						//Handle properties which is actually element fields
						if(!array_key_exists($prop_id, $this->PROPERTY_MAP))
						{
							if($prop_id == "CML2_CODE")
								$arElement["CODE"] = isset($value[$this->mess["IBLOCK_XML2_VALUE"]])? $value[$this->mess["IBLOCK_XML2_VALUE"]]: "";
							elseif($prop_id == "CML2_ACTIVE")
							{
								$value = array_pop($value);
								$arElement["ACTIVE"] = ($value=="true") || intval($value)? "Y": "N";
							}
							elseif($prop_id == "CML2_SORT")
								$arElement["SORT"] = array_pop($value);
							elseif($prop_id == "CML2_ACTIVE_FROM")
								$arElement["ACTIVE_FROM"] = CDatabase::FormatDate(array_pop($value), "YYYY-MM-DD HH:MI:SS", CLang::GetDateFormat("FULL"));
							elseif($prop_id == "CML2_ACTIVE_TO")
								$arElement["ACTIVE_TO"] = CDatabase::FormatDate(array_pop($value), "YYYY-MM-DD HH:MI:SS", CLang::GetDateFormat("FULL"));
							elseif($prop_id == "CML2_PREVIEW_TEXT")
							{
								if(array_key_exists($this->mess["IBLOCK_XML2_VALUE"], $value))
								{
									if(isset($value[$this->mess["IBLOCK_XML2_VALUE"]]))
										$arElement["PREVIEW_TEXT"] = $value[$this->mess["IBLOCK_XML2_VALUE"]];
									else
										$arElement["PREVIEW_TEXT"] = "";

									if(isset($value[$this->mess["IBLOCK_XML2_TYPE"]]))
										$arElement["PREVIEW_TEXT_TYPE"] = $value[$this->mess["IBLOCK_XML2_TYPE"]];
									else
										$arElement["PREVIEW_TEXT_TYPE"] = "html";
								}
							}
							elseif($prop_id == "CML2_DETAIL_TEXT")
							{
								if(array_key_exists($this->mess["IBLOCK_XML2_VALUE"], $value))
								{
									if(isset($value[$this->mess["IBLOCK_XML2_VALUE"]]))
										$arElement["DETAIL_TEXT"] = $value[$this->mess["IBLOCK_XML2_VALUE"]];
									else
										$arElement["DETAIL_TEXT"] = "";

									if(isset($value[$this->mess["IBLOCK_XML2_TYPE"]]))
										$arElement["DETAIL_TEXT_TYPE"] = $value[$this->mess["IBLOCK_XML2_TYPE"]];
									else
										$arElement["DETAIL_TEXT_TYPE"] = "html";
								}
							}
							elseif($prop_id == "CML2_PREVIEW_PICTURE")
							{
								if(!is_array($this->preview) || !$arElement["PREVIEW_PICTURE"])
								{
									$arElement["PREVIEW_PICTURE"] = $this->MakeFileArray($value[$this->mess["IBLOCK_XML2_VALUE"]], array("PREVIEW_PICTURE"));
									$arElement["PREVIEW_PICTURE"]["COPY_FILE"] = "Y";
								}
							}

							continue;
						}

						$prop_id = $this->PROPERTY_MAP[$prop_id];
						$prop_type = $this->arProperties[$prop_id]["PROPERTY_TYPE"];

						if(!array_key_exists($prop_id, $arElement["PROPERTY_VALUES"]))
							$arElement["PROPERTY_VALUES"][$prop_id] = array();

						//check for bitrix extended format
						if(array_key_exists($this->mess["IBLOCK_XML2_PROPERTY_VALUE"], $value))
						{
							$i = 1;
							$strPV = $this->mess["IBLOCK_XML2_PROPERTY_VALUE"];
							$lPV = strlen($strPV);
							foreach($value as $k=>$prop_value)
							{
								if(substr($k, 0, $lPV) === $strPV)
								{
									if(array_key_exists($this->mess["IBLOCK_XML2_SERIALIZED"], $prop_value))
										$prop_value[$this->mess["IBLOCK_XML2_VALUE"]] = $this->Unserialize($prop_value[$this->mess["IBLOCK_XML2_VALUE"]]);
									if($prop_type=="F")
									{
										$prop_value[$this->mess["IBLOCK_XML2_VALUE"]] = $this->MakeFileArray($prop_value[$this->mess["IBLOCK_XML2_VALUE"]], array($prop_id));
									}
									elseif($prop_type=="G")
										$prop_value[$this->mess["IBLOCK_XML2_VALUE"]] = $this->GetSectionByXML_ID($this->arProperties[$prop_id]["LINK_IBLOCK_ID"], $prop_value[$this->mess["IBLOCK_XML2_VALUE"]]);
									elseif($prop_type=="E")
										$prop_value[$this->mess["IBLOCK_XML2_VALUE"]] = $this->GetElementByXML_ID($this->arProperties[$prop_id]["LINK_IBLOCK_ID"], $prop_value[$this->mess["IBLOCK_XML2_VALUE"]]);
									elseif($prop_type=="L")
										$prop_value[$this->mess["IBLOCK_XML2_VALUE"]] = $this->GetEnumByXML_ID($this->arProperties[$prop_id]["ID"], $prop_value[$this->mess["IBLOCK_XML2_VALUE"]]);

									if(array_key_exists("bOld", $arElement["PROPERTY_VALUES"][$prop_id]))
									{
										if($prop_type=="F")
										{
											foreach($arElement["PROPERTY_VALUES"][$prop_id] as $PROPERTY_VALUE_ID => $PROPERTY_VALUE)
												$arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID] = array(
													"tmp_name" => "",
													"del" => "Y",
												);
											unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
										}
										else
											$arElement["PROPERTY_VALUES"][$prop_id] = array();
									}

									$arElement["PROPERTY_VALUES"][$prop_id]["n".$i] = array(
										"VALUE" => $prop_value[$this->mess["IBLOCK_XML2_VALUE"]],
										"DESCRIPTION" => $prop_value[$this->mess["IBLOCK_XML2_DESCRIPTION"]],
									);
								}
								$i++;
							}
						}
						else
						{
							if($prop_type == "L" && !array_key_exists($this->mess["IBLOCK_XML2_VALUE_ID"], $value))
								$l_key = $this->mess["IBLOCK_XML2_VALUE"];
							else
								$l_key = $this->mess["IBLOCK_XML2_VALUE_ID"];

							$i = 0;
							foreach($value as $k=>$prop_value)
							{
								if(array_key_exists("bOld", $arElement["PROPERTY_VALUES"][$prop_id]))
								{
									if($prop_type=="F")
									{
										foreach($arElement["PROPERTY_VALUES"][$prop_id] as $PROPERTY_VALUE_ID => $PROPERTY_VALUE)
											$arElement["PROPERTY_VALUES"][$prop_id][$PROPERTY_VALUE_ID] = array(
												"tmp_name" => "",
												"del" => "Y",
											);
										unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
									}
									else
									{
										$arElement["PROPERTY_VALUES"][$prop_id] = array();
									}
								}

								if($prop_type == "L" && $k == $l_key)
								{
									$prop_value = $this->GetEnumByXML_ID($this->arProperties[$prop_id]["ID"], $prop_value);
								}
								elseif($prop_type == "N" && isset($this->next_step["sdp"]))
								{
									if (strlen($prop_value) > 0)
										$prop_value = $this->ToFloat($prop_value);
								}

								$arElement["PROPERTY_VALUES"][$prop_id]["n".$i] = array(
									"VALUE" => $prop_value,
									"DESCRIPTION" => false,
								);
								$i++;
							}
						}
					}
				}

				//If there is no BaseUnit specified check prices for it
				if(
					(
						!array_key_exists($this->PROPERTY_MAP["CML2_BASE_UNIT"], $arElement["PROPERTY_VALUES"])
						|| (
							is_array($arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BASE_UNIT"]])
							&& array_key_exists("bOld", $arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BASE_UNIT"]])
						)
					)
					&& isset($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]])
				)
				{
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]] as $price)
					{
						if(
							isset($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]])
							&& array_key_exists($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]], $this->PRICES_MAP)
							&& array_key_exists($this->mess["IBLOCK_XML2_MEASURE"], $price)
						)
						{
							$arElement["PROPERTY_VALUES"][$this->PROPERTY_MAP["CML2_BASE_UNIT"]] = array(
								"n0" => $this->convertBaseUnitFromXmlToPropertyValue($price[$this->mess["IBLOCK_XML2_MEASURE"]]),
							);
							break;
						}
					}
				}

				if($arDBElement)
				{
					foreach($arElement["PROPERTY_VALUES"] as $prop_id=>$prop)
					{
						if(is_array($arElement["PROPERTY_VALUES"][$prop_id]) && array_key_exists("bOld", $arElement["PROPERTY_VALUES"][$prop_id]))
						{
							if($this->arProperties[$prop_id]["PROPERTY_TYPE"]=="F")
								unset($arElement["PROPERTY_VALUES"][$prop_id]);
							else
								unset($arElement["PROPERTY_VALUES"][$prop_id]["bOld"]);
						}
					}

					if(intval($arElement["MODIFIED_BY"]) <= 0 && $USER_ID > 0)
						$arElement["MODIFIED_BY"] = $USER_ID;

					if(!array_key_exists("CODE", $arElement) && is_array($this->translit_on_update))
					{
						$CODE = CUtil::translit($arElement["NAME"], LANGUAGE_ID, $this->translit_on_update);
						$CODE = $this->CheckElementCode($this->next_step["IBLOCK_ID"], $arDBElement["ID"], $CODE);
						if($CODE !== false)
							$arElement["CODE"] = $CODE;
					}

					//Check if detail picture hasn't been changed
					if (
						isset($arElement["DETAIL_PICTURE"])
						&& !isset($arElement["PREVIEW_PICTURE"])
						&& is_array($arElement["DETAIL_PICTURE"])
						&& isset($arElement["DETAIL_PICTURE"]["external_id"])
						&& $this->arElementFilesId
						&& $this->arElementFilesId["DETAIL_PICTURE"]
						&& isset($this->arElementFiles[$this->arElementFilesId["DETAIL_PICTURE"][0]])
						&& $this->arElementFiles[$this->arElementFilesId["DETAIL_PICTURE"][0]]["EXTERNAL_ID"] === $arElement["DETAIL_PICTURE"]["external_id"]
						&& $this->arElementFiles[$this->arElementFilesId["DETAIL_PICTURE"][0]]["DESCRIPTION"] === $arElement["DETAIL_PICTURE"]["description"]
					)
					{
						unset($arElement["DETAIL_PICTURE"]);
					}

					$updateResult = $obElement->Update($arDBElement["ID"], $arElement, $bWF, true, $this->iblock_resize);
					//In case element was not active in database we have to activate its offers
					if($arDBElement["ACTIVE"] != "Y")
					{
						$this->ChangeOffersStatus($arDBElement["ID"], "Y", $bWF);
					}
					$arElement["ID"] = $arDBElement["ID"];
					if($updateResult)
					{
						$counter["UPD"]++;
					}
					else
					{
						$this->LAST_ERROR = $obElement->LAST_ERROR;
						$counter["ERR"]++;
					}
				}
				else
				{
					if(!array_key_exists("CODE", $arElement) && is_array($this->translit_on_add))
					{
						$CODE = CUtil::translit($arElement["NAME"], LANGUAGE_ID, $this->translit_on_add);
						$CODE = $this->CheckElementCode($this->next_step["IBLOCK_ID"], 0, $CODE);
						if($CODE !== false)
							$arElement["CODE"] = $CODE;
					}

					$arElement["IBLOCK_ID"] = $this->next_step["IBLOCK_ID"];
					$this->fillDefaultPropertyValues($arElement, $this->arProperties);

					$arElement["ID"] = $obElement->Add($arElement, $bWF, true, $this->iblock_resize);
					if($arElement["ID"])
					{
						$counter["ADD"]++;
					}
					else
					{
						$this->LAST_ERROR = $obElement->LAST_ERROR;
						$counter["ERR"]++;
					}
				}
			}
			elseif(array_key_exists($this->mess["IBLOCK_XML2_PRICES"], $arXMLElement))
			{
				//Collect price information for future use
				$arElement["PRICES"] = array();
				if (is_array($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]]))
				{
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_PRICES"]] as $price)
					{
						if(isset($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]]) && array_key_exists($price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]], $this->PRICES_MAP))
						{
							$price["PRICE"] = $this->PRICES_MAP[$price[$this->mess["IBLOCK_XML2_PRICE_TYPE_ID"]]];
							$arElement["PRICES"][] = $price;
						}
					}
				}

				$arElement["DISCOUNTS"] = array();
				if(isset($arXMLElement[$this->mess["IBLOCK_XML2_DISCOUNTS"]]))
				{
					foreach($arXMLElement[$this->mess["IBLOCK_XML2_DISCOUNTS"]] as $discount)
					{
						if(
							isset($discount[$this->mess["IBLOCK_XML2_DISCOUNT_CONDITION"]])
							&& $discount[$this->mess["IBLOCK_XML2_DISCOUNT_CONDITION"]]===$this->mess["IBLOCK_XML2_DISCOUNT_COND_VOLUME"]
						)
						{
							$discount_value = $this->ToInt($discount[$this->mess["IBLOCK_XML2_DISCOUNT_COND_VALUE"]]);
							$discount_percent = $this->ToFloat($discount[$this->mess["IBLOCK_XML2_DISCOUNT_COND_PERCENT"]]);
							if($discount_value > 0 && $discount_percent > 0)
								$arElement["DISCOUNTS"][$discount_value] = $discount_percent;
						}
					}
				}

				if ($arDBElement)
				{
					$arElement["ID"] = $arDBElement["ID"];
					$counter["UPD"]++;
				}
			}

			if(isset($arXMLElement[$this->mess["IBLOCK_XML2_STORE_AMOUNT_LIST"]]))
			{
				$arElement["STORE_AMOUNT"] = array();
				foreach($arXMLElement[$this->mess["IBLOCK_XML2_STORE_AMOUNT_LIST"]] as $storeAmount)
				{
					if(isset($storeAmount[$this->mess["IBLOCK_XML2_STORE_ID"]]))
					{
						$storeXMLID = $storeAmount[$this->mess["IBLOCK_XML2_STORE_ID"]];
						$amount = $this->ToFloat($storeAmount[$this->mess["IBLOCK_XML2_AMOUNT"]]);
						$arElement["STORE_AMOUNT"][$storeXMLID] = $amount;
					}
				}
			}
			elseif(
				array_key_exists($this->mess["IBLOCK_XML2_STORES"], $arXMLElement)
				|| array_key_exists($this->mess["IBLOCK_XML2_STORE"], $arXMLElement)
			)
			{
				$arElement["STORE_AMOUNT"] = array();
				$rsStores = $this->_xml_file->GetList(
					array("ID" => "asc"),
					array(
						"><LEFT_MARGIN" => array($arParent["LEFT_MARGIN"], $arParent["RIGHT_MARGIN"]),
						"NAME" => $this->mess["IBLOCK_XML2_STORE"],
					),
					array("ID", "ATTRIBUTES")
				);
				while ($arStore = $rsStores->Fetch())
				{
					if(strlen($arStore["ATTRIBUTES"]) > 0)
					{
						$info = unserialize($arStore["ATTRIBUTES"]);
						if(
							is_array($info)
							&& array_key_exists($this->mess["IBLOCK_XML2_STORE_ID"], $info)
							&& array_key_exists($this->mess["IBLOCK_XML2_STORE_AMOUNT"], $info)
						)
						{
							$arElement["STORE_AMOUNT"][$info[$this->mess["IBLOCK_XML2_STORE_ID"]]] = $this->ToFloat($info[$this->mess["IBLOCK_XML2_STORE_AMOUNT"]]);
						}
					}
				}
			}

			if($bMatch && $this->use_crc)
			{
				//nothing to do
			}
			elseif($arElement["ID"] && $this->bCatalog && $this->isCatalogIblock)
			{
				$CML_LINK = $this->PROPERTY_MAP["CML2_LINK"];

				$arProduct = array(
					"ID" => $arElement["ID"],
				);

				if(isset($arElement["QUANTITY"]))
					$arProduct["QUANTITY"] = $arElement["QUANTITY"];
				elseif(isset($arElement["STORE_AMOUNT"]) && !empty($arElement["STORE_AMOUNT"]))
					$arProduct["QUANTITY"] = array_sum($arElement["STORE_AMOUNT"]);

				$CML_LINK_ELEMENT = $arElement["PROPERTY_VALUES"][$CML_LINK];
				if (is_array($CML_LINK_ELEMENT) && isset($CML_LINK_ELEMENT["n0"]))
				{
					$CML_LINK_ELEMENT = $CML_LINK_ELEMENT["n0"];
				}
				if (is_array($CML_LINK_ELEMENT) && isset($CML_LINK_ELEMENT["VALUE"]))
				{
					$CML_LINK_ELEMENT = $CML_LINK_ELEMENT["VALUE"];
				}

				if(isset($arElement["BASE_WEIGHT"]))
				{
					$arProduct["WEIGHT"] = $arElement["BASE_WEIGHT"];
				}
				elseif ($CML_LINK_ELEMENT > 0)
				{
					$rsWeight = CIBlockElement::GetProperty($this->arProperties[$CML_LINK]["LINK_IBLOCK_ID"], $CML_LINK_ELEMENT, array(), array("CODE" => "CML2_TRAITS"));
					while($arWeight = $rsWeight->Fetch())
					{
						if($arWeight["DESCRIPTION"] == $this->mess["IBLOCK_XML2_WEIGHT"])
							$arProduct["WEIGHT"] = $this->ToFloat($arWeight["VALUE"])*1000;
					}
				}

				if ($CML_LINK_ELEMENT > 0)
				{
					$rsUnit = CIBlockElement::GetProperty($this->arProperties[$CML_LINK]["LINK_IBLOCK_ID"], $CML_LINK_ELEMENT, array(), array("CODE" => "CML2_BASE_UNIT"));
					while($arUnit = $rsUnit->Fetch())
					{
						if($arUnit["DESCRIPTION"] > 0)
							$arProduct["MEASURE"] = $arUnit["DESCRIPTION"];
					}
				}

				if(isset($arElement["PRICES"]))
				{
					//Here start VAT handling

					//Check if all the taxes exists in BSM catalog
					$arTaxMap = array();
					$rsTaxProperty = CIBlockElement::GetProperty($this->arProperties[$CML_LINK]["LINK_IBLOCK_ID"], $CML_LINK_ELEMENT, "sort", "asc", array("CODE" => "CML2_TAXES"));
					while($arTaxProperty = $rsTaxProperty->Fetch())
					{
						if(
							strlen($arTaxProperty["VALUE"]) > 0
							&& strlen($arTaxProperty["DESCRIPTION"]) > 0
							&& !array_key_exists($arTaxProperty["DESCRIPTION"], $arTaxMap)
						)
						{
							$arTaxMap[$arTaxProperty["DESCRIPTION"]] = array(
								"RATE" => $this->ToFloat($arTaxProperty["VALUE"]),
								"ID" => $this->CheckTax($arTaxProperty["DESCRIPTION"], $this->ToFloat($arTaxProperty["VALUE"])),
							);
						}
					}

					//First find out if all the prices have TAX_IN_SUM true
					$TAX_IN_SUM = "Y";
					foreach($arElement["PRICES"] as $price)
					{
						if($price["PRICE"]["TAX_IN_SUM"] !== "true")
						{
							$TAX_IN_SUM = "N";
							break;
						}
					}
					//If there was found not included tax we'll make sure
					//that all prices has the same flag
					if($TAX_IN_SUM === "N")
					{
						foreach($arElement["PRICES"] as $price)
						{
							if($price["PRICE"]["TAX_IN_SUM"] !== "false")
							{
								$TAX_IN_SUM = "Y";
								break;
							}
						}
						//Check if there is a mix of tax in sum
						//and correct it by recalculating all the prices
						if($TAX_IN_SUM === "Y")
						{
							foreach($arElement["PRICES"] as $key=>$price)
							{
								if($price["PRICE"]["TAX_IN_SUM"] !== "true")
								{
									$TAX_NAME = $price["PRICE"]["TAX_NAME"];
									if(array_key_exists($TAX_NAME, $arTaxMap))
									{
										$PRICE_WO_TAX = $this->ToFloat($price[$this->mess["IBLOCK_XML2_PRICE_FOR_ONE"]]);
										$PRICE = $PRICE_WO_TAX + ($PRICE_WO_TAX / 100.0 * $arTaxMap[$TAX_NAME]["RATE"]);
										$arElement["PRICES"][$key][$this->mess["IBLOCK_XML2_PRICE_FOR_ONE"]] = $PRICE;
									}
								}
							}
						}
					}
					foreach($arElement["PRICES"] as $price)
					{
						$TAX_NAME = $price["PRICE"]["TAX_NAME"];
						if(array_key_exists($TAX_NAME, $arTaxMap))
						{
							$arProduct["VAT_ID"] = $arTaxMap[$TAX_NAME]["ID"];
							break;
						}
					}
					$arProduct["VAT_INCLUDED"] = $TAX_IN_SUM;
				}

				if(isset($arXMLElement['Ширина']))
				{
					$arProduct['WIDTH'] = (float)$arXMLElement['Ширина'];
				}

				if(isset($arXMLElement['Глубина']))
				{
					$arProduct['LENGTH'] = (float)$arXMLElement['Глубина'];
				}

				if(isset($arXMLElement['Высота']))
				{
					$arProduct['HEIGHT'] = (float)$arXMLElement['Высота'];
				}

				if (CCatalogProduct::Add($arProduct))
				{
					//TODO: replace this code after upload measure ratio from 1C
					$iterator = \Bitrix\Catalog\MeasureRatioTable::getList(array(
						'select' => array('ID'),
						'filter' => array('=PRODUCT_ID' => $arElement['ID'])
					));
					$ratioRow = $iterator->fetch();
					if (empty($ratioRow))
					{
						$ratioResult = \Bitrix\Catalog\MeasureRatioTable::add(array(
							'PRODUCT_ID' => $arElement['ID'],
							'RATIO' => 1,
							'IS_DEFAULT' => 'Y'
						));
						unset($ratioResult);
					}
					unset($ratioRow, $iterator);
				}

				if(isset($arElement["PRICES"]))
					$this->SetProductPrice($arElement["ID"], $arElement["PRICES"], $arElement["DISCOUNTS"]);

				if(isset($arElement["STORE_AMOUNT"]))
					$this->ImportStoresAmount($arElement["STORE_AMOUNT"], $arElement["ID"], $counter);
			}


			return $arElement["ID"];
		}
	}
	/*
GetMessage("IBLOCK_XML2_COEFF")
GetMessage("IBLOCK_XML2_OWNER")
GetMessage("IBLOCK_XML2_TITLE")
GetMessage("IBLOCK_XML2_VALUES_TYPE")
GetMessage("IBLOCK_XML2_VIEW")
*/

