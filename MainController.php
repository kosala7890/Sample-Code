<?php
class MainController extends AppController {
	
	public $uses = array('Language');
	public function translate($language_code) {
		global $i18n;
		include_once I18N_PATH.'default.php';
		include_once I18N_PATH.$language_code.'.php';
		
		$language = $this->Language->find('first', array('conditions' => array('code' => $language_code)));
		$language = $language[$this->Language->alias];
		$this->setTitle('Translate');
		$this->set(compact('i18n', 'language'));
	}
	
	public function save_translation($language_code) {
		$data = $this->data;
		$unset = array();
		
		foreach($data as $k => & $v) {
			if(isset($v) && '' !== trim($v)) {
				$v = $k.'" => "'.$v;
			} else {
				$unset[] = $k;
			}			
		}
		
		$data = array_diff_key($data, array_flip($unset));

		mkdir(I18N_PATH, true);
		$phpStr = '<?php $i18n["'.$language_code.'"] = array("'.implode('","', $data).'"); ?>';
		file_put_contents(I18N_PATH.'/'.$language_code.'.php', $phpStr);
		
		return $this->responseSuccess();
	}
}