<?php
class MetaController extends AppController {
	
	public function meta_list() {
		$Currency = ClassRegistry::init('Currency');
		$Timezone = ClassRegistry::init('Timezone');
		
		$this->responseSuccess(array(
			'currencies' => $Currency->all(),
			'timezones' => $Timezone->all()
		));
	}
}