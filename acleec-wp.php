<?php
/*
Plugin Name: Micro-paiement ACLEEC
Plugin URI: http://www.acleec.com
Description: Ce plugin permet de faire payer les articles du blog avec le syst&egrave;me de micropaiement ACLEEC : soit l'internaute doit cliquer sur un ticket de paiement pour visualiser l'int&eacute;gralit&eacute; de l'article, soit on lui propose de cliquer sur un paiement pour contribuer librement &agrave; votre blog. Une fois le plugin install&eacute;, il suffit d'indiquer pour chaque page le montant du paiement ou du don qui est demand&eacute; pour acc&eacute;der au contenu.
Author: Alain Bernard
Author URI: http://www.acleec.com
Version: 1.0.0
*/

//-----------------------------------------------------------------------------------------------------------------
// Installation initiale du plugin

register_activation_hook(__FILE__,'acwp_installation');

//-----------------------------------------------------------------------------------------------------------------
// Filtre de paiement

add_filter("the_content","acwp_add_ticket");	// Affiche le ticket acleec dans l'excerpt
//add_filter("the_excerpt","acwp_add_ticket");	// Affiche le ticket acleec dans l'excerpt

//-----------------------------------------------------------------------------------------------------------------
// Interface

add_action('admin_menu', 'acwp_admin_actions');  		// Menu administrateur
add_action('save_post', 'acwp_option_page_save');		// Sauvegarde les paramètres de paiement de la page
//add_action('init', 'acwp_init');						// Initialisation pour écriture dans le cookie

// -----------------------------------------------------------------------------
// Administration
// - Menu "acleec" dans les pages
// - Paramètres du paiement dans les menus page et post

function acwp_admin_actions() {  
	//add_pages_page("Paiement", "Paiement", 1, "Paiement ACLEEC", "acwp_options_page");  // Dans le menu "pages"
	add_options_page("Paiement", "Paiement", 1, "Paiement ACLEEC", "acwp_options_page");  // Dans le menu "réglages"
	add_meta_box( 'myplugin_sectionid', __( 'Page payante', 'myplugin_textdomain' ), 
                "acwp_option_page", 'page', 'advanced' );
	add_meta_box( 'myplugin_sectionid', __( 'Page payante', 'myplugin_textdomain' ), 
                "acwp_option_page", 'post', 'advanced' );
}

//---------------------------------------------------------------------------------------------------
// Nom de la table

function acwp_table_name() {
	global $wpdb;
	return $wpdb->prefix."acleec_pages";
}

//---------------------------------------------------------------------------------------------------
// Création de la table des pages payantes
// - Identifiant de la page
// - Devise
// - Prix

function acwp_installation() {

	global $wpdb;

	// table name

	$table_name = acwp_table_name();
	
	// table creation

	$sql = "CREATE TABLE $table_name (
		page_id bigint(20) UNSIGNED,
		
		charged tinyint default FALSE,
		
		eur DECIMAL(5,2) default 0,
		try DECIMAL(5,2) default 0,
		usd DECIMAL(5,2) default 0,
		gbp DECIMAL(5,2) default 0,
		jpy DECIMAL(5,0) default 0,
		
		eurTot DECIMAL(10,2) default 0,
		tryTot DECIMAL(10,2) default 0,
		usdTot DECIMAL(10,2) default 0,
		gbpTot DECIMAL(10,2) default 0,
		jpyTot DECIMAL(10,2) default 0,
		
		access INT default 0,
		
		UNIQUE KEY page_id (page_id)
		);";
		
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	$res = dbDelta($sql);
	
	// table version
	
	add_option("acwp_db_version", "1.0");
}

//---------------------------------------------------------------------------------------------------
// Statistiques de ventes
// Retourne un tableau : devise => montant

function acwp_stat_ventes() {

	global $wpdb;
	
	$sql = "SELECT sum(access) as access, sum(eurTot) as EUR, sum(tryTot) as TRY, sum(usdTot) as USD, sum(jpyTot) as JPY, sum(gbpTot) as GBP FROM %s;";
	$sql = sprintf($sql, acwp_table_name());
	
	return $wpdb->get_row($sql, ARRAY_A);
}

//---------------------------------------------------------------------------------------------------
// Tableau des options globales
// Les initialise lorsqu'il s'agit du premier appel

function acwp_get_options() {

	$s = get_option("acwp_param");
	if ($s == false) {
		$r["seller"] = "";
		$r["cat"] = 0;
		$r["validity"] = 86400;
		$r["duration"] = 3600;
		$r["message"] = addslashes("Article payant, cliquez sur le ticket <a href='http://www.acleec.com'>ACLEEC</a> pour l'acheter");
		$r["donate"] = addslashes("Contribuez &agrave; ce blog : donnez en cliquant sur le ticket <a href='http://www.acleec.com'>ACLEEC</a>");
		$r["thankyou"] = addslashes("Merci d'avoir contribu&eacute;. Gr&acirc;ce &agrave; vos dons, nous pouvons maintenir la qualit&eacute; de ce blog.");
		$s = serialize($r);
	}
	$prm["message"] = stripslashes($prm["message"]);
	$prm["donate"] = stripslashes($prm["donate"]);
	$prm["thankyou"] = stripslashes($prm["thankyou"]);
	return unserialize($s);
}	

//---------------------------------------------------------------------------------------------------
// Sauve les options globales

function acwp_save_options($prm) {
	$prm["message"] = stripslashes($prm["message"]);
	$prm["donate"] = stripslashes($prm["donate"]);
	$prm["thankyou"] = stripslashes($prm["thankyou"]);
	$s = serialize($prm);
	update_option("acwp_param",$s);
}

//---------------------------------------------------------------------------------------------------
// Bâtit un excerpt (n'existe pas pour les pages !)

function acwp_excerpt() {

	global $post;
	
	// Un excerpt a été défini
	
	$text = $post->post_excerpt;
	if ($text != "") return $text;
	
	// On part du texte brut

	$text = $post->post_content;
	
	// On coupe au tag more
	
	$tab = explode("<!--more", $text);
	if (isset($tab[1])) return $tab[0];

	// Sinon on prend les 55 premiers mots
	
	$tab = explode(" ",$text);
	$t = array_chunk($tab, 55);
	$text = implode(" ", $t[0]);
	
	return $text;

}

//---------------------------------------------------------------------------------------------------
// Retourne les paramètres de paiement d'une page

function acwp_get_price($page_id) {

	//acwp_installation(); // debug

	global $wpdb;
	
	// sql de sélection
	
	$sql = "SELECT * FROM %s WHERE page_id = %s;";
	$sql = sprintf($sql, acwp_table_name(), $page_id);

	// Lit le contenu
	
	$price = $wpdb->get_row($sql, ARRAY_A);
	if ($price == false) {
		$price = array();
		$price["loaded"] = false;
		$price["charged"] = 0;
		
	} else {
		$price["loaded"] = true;
	}
	return $price;
}

//---------------------------------------------------------------------------------------------------
// Cumule un payement

function acwp_add_payment($page_id, $curr, $price) {

	global $wpdb;

	//if ($price <= 0) return;
	
	$sql = "UPDATE %s SET %sTot = %sTot + %.2F, access=access+1 WHERE page_id=%s;";
	$sql = sprintf($sql, acwp_table_name(), $curr, $curr, str_replace(",",".",$price), $page_id);
	
	$wpdb->query($sql);

}

// -----------------------------------------------------------------------------
// Filtre d'affichage de la page
// - Si l'adresse contient un paiement, l'encaisse et affiche le contenu
// - Si l'adresse ne contient pas de paiement, affiche juste l'excerpt et un ticket de paiemen

function acwp_add_ticket($text) {

	global $wpdb;
	global $post;
	
	$page_id = $post->ID;			// Page ID
	$prm = acwp_get_options();		// Paramètres globaux
	$seller = $prm["seller"];		// Identifiant vendeur		
	
	//--------------------------------------------------------------------------
	// Récupère les informations de paiement
	
	$price = acwp_get_price($page_id);
	if ($price["charged"] == 0) return $text;
	$donate = ($price["charged"] == 2);

	//--------------------------------------------------------------------------
	// Message préalable : affiché avant le content ou l'excerpt
	// Contient la référenc à la feuille de style acleec et le message
	// éventuel
	
	$pre_msg = "<link rel='stylesheet' href='".get_option("home")."/wp-content/plugins/acleec-wp/acleec-wp.css' type='text/css' media='screen' />";
	
	//--------------------------------------------------------------------------
	// Construit le ticket de paiement
	
	$ticket = "";
	if ($donate) 
		$ticket .= "<br/><div class='acleec-donate'>".$prm["donate"];
	else
		$ticket .= "<br/><div class='acleec-more'>".$prm["message"];
	
	$ticket .= "<div style=\"float: right; margin: 0px 0px 5px 5px; padding: 0; width: 80; height: 28px\">";
	$ticket .= "<IFRAME src=\"http://www.acleec.com/showoffer.php";
	$ticket .= "?till=".str_replace("?","&",get_permalink($post->ID));
	$ticket .= "&object=$page_id";
	$ticket .= "&seller=$seller";
	$ticket .= "&cat=".$prm["cat"];
	$ticket .= "&validity=".$prm["validity"];
	$ticket .= "&duration=".$prm["duration"];
	if ($donate) $ticket .= "&donate=yes";
	
	if ($price['eur'] > 0) $ticket .= sprintf("&EUR=%.2F", $price['eur']);
	if ($price['usd'] > 0) $ticket .= sprintf("&USD=%.2F", $price['usd']);
	if ($price['gbp'] > 0) $ticket .= sprintf("&GBP=%.2F", $price['gbp']);
	if ($price['jpy'] > 0) $ticket .= sprintf("&JPY=%.0F", $price['jpy']);
	if ($price['try'] > 0) $ticket .= sprintf("&TRY=%.2F", $price['try']);
	
	$ticket .= '" width="80" height="28" margin="0" scrolling="no" frameborder="0">[iframe !]</IFRAME>';
	$ticket .= "</div>";
	$ticket .= "</div>";

	//--------------------------------------------------------------------------
	// Il y a un paiement dans l'url
	
	if (isset($_GET["signature"])) {
	
		$url_cnp = "http://www.acleec.com/checkandpay.php";		// url d'encaissement
		$url_cnp .= "?".$_SERVER['QUERY_STRING'];				// ajoute les paramètres contenus dans l'url
		$res_cnp = file_get_contents($url_cnp);					// encaissement auprès de ACLEEC
		$msg_cnp = explode("|",$res_cnp);						// éclate le résultat dans un tableau
		
		//----- Le paiement est ok
		
		if ($msg_cnp[0] == "ok") {
			acwp_add_payment($page_id, $_GET["curr"], $_GET["price"]);		// Mémorise le paiement pour cette page
			$pre_msg .= "<span class='acleec-paid'>".$msg_cnp[4]."</span>";	// Message préalable : retour de ACLEEC
			if (isset($_GET["donate"]))										// Message de remerciement
				if ($_GET["donate"] == "yes")
					$pre_msg .= "<span class='acleec-thankyou'>".$prm["thankyou"]."</span>";
			return $pre_msg.$text;											// On affiche l'intégralité de la page
		}
		
		//----- Le paiement est ko
		// construction d'un message préalable d'erreur et ensuite affichage comme s'il n'y avait pas de paiement
		

		if (isset($msg_cnp[4])) {				// Message 4 défini (sinon, l'erreur est technique)
			$msg = $msg_cnp[2]; 
		} else {
			$msg = "unknowed error : impossible to pay";
		}
		$pre_msg .= "<span class='acleec-error'>$msg</span>";	// message préalable = message d'erreur
	}

	//--------------------------------------------------------------------------
	// Si la page est payante (ie n'est pas gérée par don), on change le texte
	// par l'excerpt pour n'afficher que lui
	
	if (!$donate) {
	
		remove_filter("the_content","acwp_add_ticket");		// On va éviter de se mordre la queue

		//$text = get_the_excerpt();						// Récupère l'excerpt
		$text = acwp_excerpt();								// get_the_excerpt ne fonctionne pas avec les pages, seulement les posts
		$text = apply_filters('get_the_excerpt', $text);	// Applique les filtres
		$text = apply_filters('the_excerpt', $text);		// Applique les filtres
		$text = str_replace(']]>', ']]>', $text);			

		add_filter("the_content","acwp_add_ticket");		// Ok, la queue n'est pas mordue
	}

	//--------------------------------------------------------------------------
	// On retourne le message préalable suivi du texte et du ticket
	
	return $pre_msg.$text.$ticket;
	
}

// -----------------------------------------------------------------------------
// Formulaire de saisie des paramètres globaux (sous-menu de pages)

function acwp_options_page() {

	$nonce_string = "acleec-param-global-definition";
	
	//------------------------------------------------------------------------------
	// Retour d'une demande de sauvegarde des paramètres
	
	if (isset($_POST[$nonce_string])) {
	
		if (!wp_verify_nonce($_POST[$nonce_string], $nonce_string)) {
		
			echo $_POST[$nonce_string]."<p>";
			echo "<strong style='color: red'>Erreur de s&eacute;curit&eacute; : les donn&eacute;es n'ont pas &eacute;t&eacute; sauvegard&eacute;es !</strong>";
			
		} else {
		
			$prm = array();
			$prm["seller"] = $_POST["seller"];
			$prm["cat"] = $_POST["cat"];
			$prm["duration"] = $_POST["duration"];
			$prm["validity"] = $_POST["validity"];
			$prm["message"] = $_POST["message"];
			$prm["donate"] = $_POST["donate"];
			$prm["thankyou"] = $_POST["thankyou"];
			
			acwp_save_options($prm);
			echo "<em>Donn&eacute;es sauvegard&eacute;es.</em>";
			
		}
	}

	//----- Lit les options

	$prm = acwp_get_options();
	
	//----- Lit les statistiques de ventes
	
	$stat = acwp_stat_ventes();

	//----- Formulaire de saisie des paramètres globaux
	
	?>
	<style>
	<?php include("acleec-form.css"); ?>
	</style>
	
	<div class="form-acleec" style="width: 700px">
	<h2>Param&eacute;trage du paiement avec ACLEEC</h2>
	<p><strong>Pour pouvoir &ecirc;tre pay&eacute;, il est n&eacute;cessaire d'avoir ouvert au pr&eacute;alable un compte <a href="http://www.acleec.com">ACLEEC</a>.
	L'ouverture est compl&egrave;tement gratuite.</strong></p>
	<em><p>ACLEEC ne g&egrave;re pas &agrave; ce jour d'argent r&eacute;el mais seulement de la <strong>try money</strong>. D&egrave;s que nous
	aurons suffisamment d'inscrits, nous commencerons les versements en argent r&eacute;el.</p>
	<p><a href="http://www.acleec.com">Inscrivez-vous maintenant</a> et vous pourrez tester gratuitement la simplicit&eacute; de ACLEEC pour mon&eacute;tiser votre blog.</p></em>
	
	<form action="#" method="post" name="f">
	
	<input type="submit" id="submit" name="submit" value="sauver les param&egrave;tres" tabindex="7" title="Cliquez pour sauver votre saisie"><br/>
	
	<fieldset>
	<legend>Vendeur</legend>
		<label for="seller" class="required" accesskey="V">Identifiant vendeur : </label>
			<input type="text" id="seller" name="seller" tabindex="1" value="<?php echo $prm["seller"]; ?>" title="Identifiant vendeur ACLEEC"><br/>
			<small>Votre identifiant vendeur est donn&eacute; sur le site <a href="http://www.acleec.com">ACLEEC</a> lorsque vous ouvrez votre compte.
			La saisie de cet identifiant est obligatoire pour que les paiements soient vers&eacute;s sur votre compte.</small>
	</fieldset>
	<fieldset>
		<legend>Param&egrave;tres</legend>
		
		<label for="cat" accesskey="C">Cat&eacute;gorie : </label>
		<input type="radio" name="cat" value="0" <?php if ($prm["cat"] == 0) echo "checked"; ?>>&nbsp;G&eacute;n&eacute;ral</input><br/>
		<input class="radio-class" type="radio" name="cat" value="1" <?php if ($prm["cat"] == 1) echo "checked"; ?>>&nbsp;Enfance</input><br/>
		<input class="radio-class" type="radio" name="cat" value="2" <?php if ($prm["cat"] == 2) echo "checked"; ?>>&nbsp;Education</input><br/>
		<input class="radio-class" type="radio" name="cat" value="4" <?php if ($prm["cat"] == 4) echo "checked"; ?>>&nbsp;Loisirs</input><br/>
		<input class="radio-class" type="radio" name="cat" value="8" <?php if ($prm["cat"] == 8) echo "checked"; ?>>&nbsp;Information</input><br/>
		<input class="radio-class" type="radio" name="cat" value="16" <?php if ($prm["cat"] == 16) echo "checked"; ?>>&nbsp;Adulte</input><br />
	
			<small>La cat&eacute;gorie est notamment utilis&eacute;e pour la protection des mineurs.</small>
			
		<label for="duration" accesskey="D">Dur&eacute;e de l'achat : </label>
			<input type="text" id="duration" name="duration" style="text-align: right;" value="<?php echo $prm["duration"]; ?>" tabindex="3" title="Dur&eacute;e de l'achat"><br/>
			<small>La dur&eacute;e indique, en secondes, le temps durant lequel la page achet&eacute;e par l'internaute
			sera propos&eacute;e gratuitement pour une nouvelle consultation. Cette dur&eacute;e ne peut pas exc&eacute;der 86400 secondes soit 1 jour.</small>
			
		<label for="validity" accesskey="a">Validit&eacute; de l'offre : </label>
			<input type="text" id="validity" name="validity" style="text-align: right;" value="<?php echo $prm["validity"]; ?>" tabindex="4" title="Dur&eacute;e de validit&eacute; de l'offre"><br/>
			<small>La dur&eacute;e de validit&eacute; indique, en secondes, le temps durant lequel l'offre de prix est valide. Au del&agrave; de cette
			dur&eacute;e, l'internaute devra raffra&icirc;chir la page. La validit&eacute; ne peut pas exc&eacute;der 86400 secondes soit 1 jour.</small>
			
	</fieldset>

	<fieldset>
		<legend>Messages</legend>
		<label for="message" accesskey="M">Message : </label>
			<textarea id="message" name="message" rows="5" cols="40" tabindex="5" title="Message de paiement"><?php echo $prm["message"]; ?></textarea><br/>
			<small>D&eacute;finissez le message qui est affich&eacute; au bas du r&eacute;sum&eacute; pour indiquer que le contenu est payant.
			Ce message est suivi du ticket de paiement ACLEEC.</small>

		<label for="donate" accesskey="C">Contribution : </label>
			<textarea id="donate" name="donate" rows="5" cols="40" tabindex="6" title="Message de contribution volontaire"><?php echo $prm["donate"]; ?></textarea><br/>
			<small>D&eacute;finissez le message qui est affich&eacute; lorsque vous proposez &agrave; vos visiteurs de faire un don de soutien &agrave; votre site.</small>

		<label for="thankyou" accesskey="T">Remerciement : </label>
			<textarea id="thankyou" name="thankyou" rows="5" cols="40" tabindex="7" title="Message de remerciement pour une contribution volontaire"><?php echo $prm["thankyou"]; ?></textarea><br/>
			<small>D&eacute;finissez le message de remerciement d'un don de soutien &agrave; votre site.</small>
			
	</fieldset>

	<?php $nonce=wp_nonce_field($nonce_string, $nonce_string); ?>
	
	<input type="submit" id="submit" name="submit" value="sauver les param&egrave;tres" tabindex="7" title="Cliquez pour sauver votre saisie"><br/>
	
	</form>	


	<h3>Statistiques de ventes</h3>

	<table style="table-layout: fixed; border: 0p; border-collapse: collapse; margin-top: 20px;">
	<tr><td width="300">Nombre d'acc&egrave;s :</td><td align="right"><?php echo sprintf("%.0f", $stat["access"]); ?></td></tr>
	<tr><td>Total des paiements en euros :</td><td align="right"><?php echo sprintf("%.2f", $stat["EUR"]); ?></td></tr>
	<tr><td>Total des paiements en <strong>try money</strong> :</td><td align="right"><?php echo sprintf("%.2f", $stat["TRY"]); ?></td></tr>
	</table>
	
	
	</div>
	
	<?php
	
}

// -----------------------------------------------------------------------------
// Affiche le paramétrage de la page ou du post

function acwp_option_page($page) {

	//-----------------------------------------------------------------------
	// Chargement des informations de prix

	$page_id = $page->ID;
	$price = acwp_get_price($page_id);
	
	//-----------------------------------------------------------------------
	// Affiche les informations sur la page
	
	echo "<style>";
	include("acleec-form-page.css");
	echo "</style>";
	
	?>
	
	<div class="form-acleec" style="width: 700px">
	
	<p><strong>V&eacute;rifiez que vous avez saisi votre identifiant vendeur dans les param&egrave;tres globaux.</strong></p>
	<em><p>ACLEEC ne g&egrave;re pas &agrave; ce jour d'argent r&eacute;el mais seulement de la <strong>try money</strong>. D&egrave;s que nous
	aurons suffisamment d'inscrits, nous commencerons les versements en argent r&eacute;el.</p>
	<p><a href="http://www.acleec.com">Inscrivez-vous maintenant</a> et vous pourrez tester gratuitement la simplicit&eacute; de ACLEEC pour mon&eacute;tiser votre blog.</p></em>
	
	<fieldset>
	<legend>Prix</legend>
	
		<label for="acwp_charged" accesskey="P">&nbsp; Page payante : </label>
			<input type="radio" name="acwp_charged" value="0" <?php if ($price["charged"] == 0) echo "checked"; ?>>&nbsp;Gratuite</input><br/>
			<input class="radio-class" type="radio" name="acwp_charged" value="1" <?php if ($price["charged"] == 1) echo "checked"; ?>>&nbsp;Payante</input><br/>
			<input class="radio-class" type="radio" name="acwp_charged" value="2" <?php if ($price["charged"] == 2) echo "checked"; ?>>&nbsp;Contribution (don)</input><br/>
			<!--
			<input type="checkbox" id="charged" name="acwp_charged" tabindex="1" <?php if ($price["charged"]) echo "checked"; ?> value="<?php echo $page_id; ?>" title="Indique si la page est payante">Cette page est payante</input><br/>
			-->
			<small>Lorsqu'une page est payante, seul le r&eacute;sum&eacute; est affich&eacute;. N'oubliez pas de sp&eacute;cifier le
			r&eacute;sum&eacute; que vous voulez afficher pour inciter vos visiteurs &agrave; payer pour lire le contenu dans sa globalit&eacute;.
			Lorsque vous demandez une contribution, la proposition est affich&eacute;e en bas de page.</small>

		<label for="acwp_eur" accesskey="e">Prix en euros : </label>
			<input type="text" style="text-align: right;" id="eur" name="acwp_eur" tabindex="2" value="<?php echo $price["eur"]; ?>" title="Prix de la page en euros" /><br/>
			<small><p>Le prix doit &ecirc;tre compris entre 0,05 et 2 euros.</p>
			<p>En attendant que ACLEEC accepte les euros, le prix sera affich&eacute; en <strong>try money</strong></p>
			</small>
			
		<input type="hidden" name="acwp_page_id" value="<?php echo $page_id; ?>" />
			
	</fieldset>
	
	<table style="table-layout: fixed; border: 0p; border-collapse: collapse; margin-top: 20px;">
	<tr><td width="300">Nombre d'acc&egrave;s :</td><td align="right"><?php echo sprintf("%.0f", $price["access"]); ?></td></tr>
	<tr><td>Total des paiements en euros :</td><td align="right"><?php echo sprintf("%.2f", $price["eurTot"]); ?></td></tr>
	<tr><td>Total des paiements en <strong>try money</strong> :</td><td align="right"><?php echo sprintf("%.2f", $price["tryTot"]); ?></td></tr>
	</table>
	
	</div>
	
	<?php
	
	return;
	
	
	$prot = acwp_est_protegee($page_id);
	$prot_em = acwp_est_protegee_elle_meme($page_id);
	
	if ($prot_em) {
		$statut = "La page $page_id est prot&eacute;g&eacute;e";
	} else {
		if ($prot)
			$statut = "La page $page_id est rattach&eacute;e &agrave; une page prot&eacute;g&eacute;e";
		else
			$statut = "La page $page_id n'est pas prot&eacute;g&eacute;e ni rattach&eacute;e &agrave; une page prot&eacute;g&eacute;e";
	}
	
	$chk = "";
	if ($prot_em)
		$chk = " checked='checked'";
		
	$s .= <<<OYO
	<p><strong>$statut</strong></p>\n
	<input type="checkbox" name="acwp_page_check" value="$page_id" $chk>Prot&eacute;ger cette page par mot de passe</input>
OYO;

	echo $s;
}

// ----------------------------------------------------------------------
// Sauve les données de paiement de la page

function acwp_option_page_save($page_id) {

	if (!isset($_POST["acwp_page_id"]))
		return;

	//-----------------------------------------------------------------------
	// Chargement des informations de prix actuelles

	$page_id = $_POST["ID"];
	$price = acwp_get_price($page_id);
	global $wpdb;

	//-----------------------------------------------------------------------
	// Aucun paiement demandé
	
	if ($_POST["acwp_charged"] == 0) {
	
		if ( !$price["loaded"] ) return;	// La page n'est pas dans la liste : rien à changer
		
		$price["charged"] = false;			// Sauvegarde l'option "non payante"

	//-----------------------------------------------------------------------
	// Paiement ou don demandé
		
	} else {
	
		$price["charged"] = $_POST["acwp_charged"];
		$price["eur"] = $_POST["acwp_eur"];
		if ($price["eur"] < 0.05) $price["eur"] = 0.05;
		if ($price["eur"] > 2) $price["eur"] = 2;
		$price["try"] = $price["eur"];
		
	}
		
	//-----------------------------------------------------------------------
	// Sql d'insertion ou de mise à jour
	
	if ($price["loaded"]) {
		$sql = "UPDATE %s SET eur=%.2F, try=%.2F, charged=%s WHERE page_id=%s;";
	} else {
		$sql = "INSERT INTO %s (eur, try, charged, page_id) VALUE (%.2F, %.2F, %s, %s);";
	}
	$sql = sprintf($sql, acwp_table_name(), $price["eur"], $price["try"], $price["charged"], $page_id);
	
	//-----------------------------------------------------------------------
	// Mise à jour

	$wpdb->query($sql);
	
	return $page_id;
	
}

// -----------------------------------------------------------------------------
// Initialisation : appelé au chargement de la page pour pouvoir écrire le cookie

function acwp_init() {

	header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
	return;

}

?>

