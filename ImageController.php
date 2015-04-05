<?php
class ImageController extends AppController {
	
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('generate_barcode', 'generate_qrcode');
	}
	
	public function generate_qrcode($type, $data) {
		$data = empty($data) ? $this->request->query('d') : $data;
		$type = empty($type) ? $this->request->query('t') : $type;
		$size = $this->request->query('s');
		$level = $this->request->query('l');
		$margin = $this->request->query('m');
	
		$size = empty($size) ? 10 : $size;
		$level = empty($level) ? 3 : $level;
		$margin = empty($margin) ? 2 : $margin;
	
		if(!empty($data)) {
			include_once VENDORS.'phpqrcode/qrlib.php';
			QRcode::$type($data, false, $level, $size, $margin);
			die;
		}
		echo 'qr'; die;
	}
	
	public function generate_barcode($type, $data) {
		$data = empty($data) ? $this->request->query('d') : $data;
		$type = empty($type) ? $this->request->query('t') : $type;
		$encode_type = $this->request->query('e');
		$show_text = $this->request->query('t');
	
		$encode_type = empty($encode_type) ? 39 : $encode_type;
		$className = 'BCGcode'.$encode_type;
		$show_text = empty($show_text) ? 1 : $show_text;
	
		if(!empty($data)) {
			include_once VENDORS.'barcodegen/class/BCGFontFile.php';
			include_once VENDORS.'barcodegen/class/BCGColor.php';
			include_once VENDORS.'barcodegen/class/BCGDrawing.php';
			include_once VENDORS.'barcodegen/class/BCGcode'.$encode_type.'.barcode.php';
				
			$image_type = array('png' => BCGDrawing::IMG_FORMAT_PNG, 'jpg' => BCGDrawing::IMG_FORMAT_JPEG, 'gif' => BCGDrawing::IMG_FORMAT_GIF);
				
			$color_black = new BCGColor(0, 0, 0);
			$color_white = new BCGColor(255, 255, 255);
			$font = 0;
				
			if(!empty($show_text)) {
				$font = new BCGFontFile(VENDORS.'barcodegen/font/Arial.ttf', 18);
			}
			$code = new $className();
			$code->setScale(2);
			$code->setThickness(30);
			$code->setForegroundColor($color_black);
			$code->setBackgroundColor($color_white);
			$code->setFont($font);
			$code->parse($data);
				
			$drawing = new BCGDrawing('', $color_white);
			$drawing->setBarcode($code);
			$drawing->draw();

			header('Accept-Ranges: bytes');
			header('Content-Type: image/'.$type);
			$drawing->finish($image_type[$type]);
			exit;
		}
		echo 'bar'; die;
	}
}