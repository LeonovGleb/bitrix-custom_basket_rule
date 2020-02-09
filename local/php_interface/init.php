<?php

use Bitrix\Main\Loader;

$eventManager = \Bitrix\Main\EventManager::getInstance();

if (Loader::includeModule('sale')) {

	$eventManager->addEventHandlerCompatible("sale", "OnCondSaleActionsControlBuildList", [
		"SaleActionDiscountEachXForY",
		"GetControlDescr"
	]);

	/**
	 * Класс который добавляет кастомное действие (каждый X товар за Y стоимость) в правила работы с корзиной
	 */
	class SaleActionDiscountEachXForY extends \CSaleActionCtrlBasketGroup
	{
		public static function GetClassName()
		{
			return __CLASS__;
		}

		public static function GetControlID()
		{
			return "DiscountEachXForY";
		}

		public static function GetControlDescr()
		{
			return parent::GetControlDescr();
		}

		public static function GetAtoms()
		{
			return static::GetAtomsEx(false, false);
		}

		/**
		 * Метод обрабатывает отображение с текстом и нужными полями
		 */
		public static function GetControlShow($arParams)
		{
			$arAtoms = static::GetAtomsEx(false, false);
			$arResult = [
				"controlId" => static::GetControlID(),
				"group" => false,
				"label" => "Каждый X товар за Y стоимость",
				"defaultText" => "",
				"showIn" => static::GetShowIn($arParams["SHOW_IN_GROUPS"]),
				"control" => [
					"Каждый",
					$arAtoms["xitems"],
					"товар за",
					$arAtoms["yprice"],
					"стоимость"
				]
			];

			return $arResult;
		}

		/**
		 * Метод формирует массив с параметрами для полей, которые нужно заполнить
		 */
		public static function GetAtomsEx($strControlID = false, $boolEx = false)
		{
			$boolEx = (true === $boolEx ? true : false);
			$arAtomList = [
				'xitems' => [
					'JS' => [
						'id' => 'xitems',
						'name' => 'x_items',
						'type' => 'input'
					],
					'ATOM' => [
						'ID' => 'xitems',
						'FIELD_TYPE' => 'string',
						'FIELD_LENGTH' => 255,
						'MULTIPLE' => 'N',
						'VALIDATE' => ''
					]
				],
				'yprice' => [
					'JS' => [
						'id' => 'yprice',
						'name' => 'y_price',
						'type' => 'input'
					],
					'ATOM' => [
						'ID' => 'yprice',
						'FIELD_TYPE' => 'string',
						'FIELD_LENGTH' => 255,
						'MULTIPLE' => 'N',
						'VALIDATE' => ''
					]
				],
			];
			if (!$boolEx) {
				foreach ($arAtomList as &$arOneAtom) {
					$arOneAtom = $arOneAtom["JS"];
				}
				if (isset($arOneAtom)) {
					unset($arOneAtom);
				}
			}
			return $arAtomList;
		}

		/**
		 * Метод формирует строку которая содержит код php, который потом исполнится при применении скидки. Кажется с помощью eval()
		 * В конкретном случае вызовится метод данного классв applyProductDiscount, который изменит стоимость товаров, согласно логике
		 */
		public static function Generate($arOneCondition, $arParams, $arControl, $arSubs = false)
		{
			$mxResult = __CLASS__ . "::applyProductDiscount(" . $arParams["ORDER"] . ", " . $arOneCondition["xitems"] . ", " . $arOneCondition["yprice"] . ");";

			return $mxResult;
		}

		/**
		 * Каждому товару X в корзине назначит стоимость Y
		 * скидка применяется сначала к самым дешевым товарам
		 * @param $arOrder
		 * @param $xItems - Каждый X товар
		 * @param $yPrice - За Y цену
		 */
		public static function applyProductDiscount(&$arOrder, $xItems, $yPrice)
		{
			$userId = $arOrder['USER_ID'];
			if ($userId && $xItems && $yPrice) {

				// Посчитаем общее количество позиций товаров в заказе и создадим вспомогательный массив с товарами отсортированными по цене
				$totalCountItems = 0;
				$arMapIdCheapestItems = [];

				foreach ($arOrder['BASKET_ITEMS'] as $product) {
					$cheapestItemId = false;

					foreach ($arOrder['BASKET_ITEMS'] as $product2) {
						if (in_array($product2['ID'], $arMapIdCheapestItems))
							continue;

						if ($cheapestItemId === false || $arOrder['BASKET_ITEMS'][$cheapestItemId]['PRICE'] > $product2['PRICE'])
							$cheapestItemId = $product2['ID'];
					}

					$arMapIdCheapestItems[] = $cheapestItemId;
					$totalCountItems += $product['QUANTITY'];
				}
				unset($product);
				unset($product2);

				// Узнаем сколько товаров нужно продать по Y стоимости, для этого посчитаем общее количество позиций и поделим без остатка на X из условий скидки
				$countItemToDiscount = ($totalCountItems >= $xItems) ? intdiv($totalCountItems, $xItems) : 0;

				// Запишем в файл отладки
				/*Bitrix\Main\Diag\Debug::writeToFile([
					'countItemToDiscount' => $countItemToDiscount,
					'arMapIdCheapestItems' => $arMapIdCheapestItems,
					'$xItems' => $xItems,
					'$yPrice' => $yPrice,
					'$totalCountItems' => $totalCountItems
				], "", "/discountdebug.txt");*/

				//Применяем скидку
				foreach ($arMapIdCheapestItems as $productId) {
					$product = &$arOrder['BASKET_ITEMS'][$productId];

					if ($product['QUANTITY'] >= $countItemToDiscount) {
						$product['PRICE'] = ($product['PRICE'] * ($product['QUANTITY'] - $countItemToDiscount) + $yPrice * $countItemToDiscount) / $product['QUANTITY'];
						$product['DISCOUNT_PRICE'] = $product['BASE_PRICE'] - $product['PRICE'];
						//$product['CUSTOM_PRICE'] = 'Y';

						$countItemToDiscount = 0;
					} else {
						$product['PRICE'] = $yPrice;
						$product['DISCOUNT_PRICE'] = $product['BASE_PRICE'] - $product['PRICE'];
						//$product['CUSTOM_PRICE'] = 'Y';

						$countItemToDiscount -= $product['QUANTITY'];
					}

					// Запишем в файл отладки
					/*Bitrix\Main\Diag\Debug::writeToFile([
						'$product' => $product
					], "", "/discountdebug.txt");*/

					if ($countItemToDiscount <= 0)
						break;
				}
				unset($productId);
			}
		}
	}
}


