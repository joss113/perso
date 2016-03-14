<?php
/**
* Script exécuté le premier du Mois
* Récupération des données CDR et mise en page sur un CSV
* suivit de l'envoi d'un email.
* Créateur: Josselin Rouveau
**/


/***************************
* Définition des Fonctions *
****************************/

/* Fonction de préparation & d'envoi de l'email */
function send_email_with_attachment($destination,$subject,$body,$file=null){
    // Utilisation de PHPMAILER pour l'envoi des mails en SMTP
    require 'mail/PHPMailerAutoload.php'; // user phpmailer to send emails
    $mail = new PHPMailer;
    $mail->isSMTP();                                  
    $mail->Host = '172.16.101.19'; 
    $mail->SMTPAuth = false;        
    $mail->setFrom("asterisk-server@roullier.com");
    $mail->addAddress($destination);
    if (!isset($file)) {$mail->addAttachment($file);} // Si un fichier est présent
    $mail->isHTML(true);                  
    $mail->Subject = $subject;
    $mail->Body    = $body;
    if(!$mail->send()){syslog(LOG_ERR, "$_SERVER[\"PHP_SELF\"]: Email Error!");}else{syslog(LOG_NOTICE, "$_SERVER[\"PHP_SELF\"]: Email Send!");} // Ajout du status dans syslog
}

/* Fonction de connexion & d'éxécution SQL */
function sql_request($request,$database){
   // SQL Connexion
   $db = mysql_connect('localhost', 'root', 'debian');
   // SQL Selection de la database
   mysql_select_db($database,$db);
   // SQL Exécution de la requête
   if (empty($request)) { 
	   	syslog(LOG_ERR, "$_SERVER[\"PHP_SELF\"]: No Data in SQL Request. script abort");
	   	die("No SQL Data");
   }
   $tab_data_sql = mysql_query($request);

   // Stockage des données SQL dans un tableau associatif
	if (!$tab_data_sql) { die("No SQL data");}
	$tab = array();
    if ($tab_data_sql)
    {
        while($row = mysql_fetch_assoc($tab_data_sql))
        {
            $tab[] = $row;
        }
    }
	return $tab;
}

/* Fonction de convertion du temps en secondes vers le format: HH-MM-SS */
function sec_en_hms($t)
{
	$s=$t%60; $t=($t-$s)/60;
	$m=$t%60;
	$h=($t-$m)/60;
	if($m<10){$m="0".$m;}
	if($s<10){$s="0".$s;}
	return "$h:$m:$s";
}

/* Fonction de récupération du mois selectionné (si vide = mois courant) */
function get_month($value=null){ // +X / -X
    date_default_timezone_set('Europe/Paris');
    $month = array("","Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre");
    if(!empty($value)){
		$selectedMonth = date("m", strtotime("$value month"));
		$year = date("Y", strtotime("$value month"));
	}else{
		$selectedMonth = date("m");
		$year = date("Y");
	}

	$return['digit'] = $selectedMonth;
	if (preg_match("/0*/", $selectedMonth)) {
		$selectedMonth = substr($selectedMonth, 1);
	}
    $return['month'] = $month[$selectedMonth];
	$return['year'] = $year;
    return $return;
}


/**
* Utilisation de la fonction:
*	traitement_sql(Tableau Résultat SQL, Tableau de TRI, N° de l'Extension)
*	Tableau de Tri: 
*		-	DEFINE: Définit une fonction externe. Actuellement seul monthXX est disponible
*		-	NUMERO: supprime le n° d'extension et suppression du DST et SRC
*		-	TAPPEL: remplace dtcontext par une information plus lisible
**/
function traitement_sql($tab,$tabFilter,$extension){
	// Traitement du tableau résultat par résultat 
    foreach ($tab as $key => $value) {
    	// Pour chaque résultat, je parcours le tableau pour me donner l'ordre des informations
    	foreach ($tabFilter as $k => $v) {
    		/*
    		* FILTRE APPEL DE FONCTION: Je cherche si un fonction externe intervient
    		*/
    		if (preg_match("/DEFINE.*/", $v)){
    			/*
    			* FILTRE MONTH: récupère le mois +/- valeur
    			*/
	            if (preg_match("/.*month(.*)/", $v,$valueGetMonth)){
	                $value[$v] = $valueGetMonth['1'];
	            }
       		}
       		/* 
       		* FILTRE NUMERO Je cherche si il faut regrouper deux colonnes avec un seul numéro (différent de l'extension)
       		*/
       		if (preg_match("/NUMERO/", $v)){
	            if (preg_match("/$extension/", $value['src'])){
					$tempTab[$v] = $value['dst'];
	            }elseif (preg_match("/$extension/", $value['dst'])){
	                $tempTab[$v] = $value['src'];
	            }
	            if (strlen($tempTab[$v]) == 9) {
	            	 $tempTab[$v] = "0".$tempTab[$v];
	            }
	            if (empty($tempTab[$v])) {
	            	$tempTab[$v] = "N/A";
	            }
       		}
       		// Sinon je conserve la valeur
       		else{
       			if (preg_match("/calldate/", $v)) {
       				$temp = explode(" ", $value[$v]);
       				$tempTab[$v] = $temp[0];
       				unset($temp);
       			}
       			elseif (preg_match("/duration/", $v)) {
       				$tempTab[$v] = sec_en_hms($value[$v]);
       			}
       			elseif (preg_match("/dcontext/", $v)) {
       				if ($value[$v] == "ext-local") {
       					if (strlen($value["src"]) == 5 && strlen($value["dst"]) == 5) {
       						$tempTab[$v] = "Appel interne";
       					}
       					elseif (preg_match("/\*.*/", $value["src"]) || preg_match("/\*.*/", $value["dst"])) {
       						$tempTab[$v] = "Appel n° abrégé interne";
       					}else{
       						$tempTab[$v] = "Appel publique vers intérieur";
       					}
       				}
       				if ($value[$v] == "from-internal") {
       					if (preg_match("/\*.*/", $value["src"]) || preg_match("/\*.*/", $value["dst"])) {
       						$tempTab[$v] = "Appel n° abrégé interne";
       					}else{
       						$tempTab[$v] = "Appel interne vers publique";
       					}
       				}
       			}
       			elseif (!empty($value[$v])) {
       				$tempTab[$v] = $value[$v];
       			}
       			else{$tempTab[$v] = "N/A";}
       		}
    	}
    	// J'enregistre les données de cette ligne / Je cumule les lignes
    	$newTab[] = $tempTab;
    }
    unset($tab);
    unset($tempTab);
    $newTab = array_map('array_filter', $newTab);
    $newTab = array_filter($newTab);    
    // Fermeture de la connexion sql
    mysql_close();
    // Return multidimensional tab with content values
    return array_filter($newTab);
}

/* Fonction de création du fichier csv */
function create_csv_file($rows=false, $filename=false, $headings=false)
{
    # Ensure that we have data to be able to export the CSV
    if ((!empty($headings)) AND (!empty($rows)))
    {
        # modify the name somewhat
        $name = ($filename !== false) ? $filename . ".csv" : "export.csv";
        # Set the headers we need for this to work
        @header('Content-Type: text/csv; charset=utf-8');
        @header('Content-Disposition: attachment; filename=' . $name);
        # Start the ouput
        if (DEBUG == true) { $output = fopen("php://output", 'w');}
        else{
        	$output = fopen("rapport/".$name, 'w+');
        	ftruncate($output,0);
    	}
        # Create the headers
        $tableau_menu = array(
        	'0' => "TYPE D'APPEL", 
        	'1' => "DATE", 
        	'2' => "MOIS", 
        	'3' => "DUREE", 
        	'4' => "N° APPELE / APPELANT"
        );
        $empty_tab = array('0' => "",'1' => "",'2' => "",'3' => "",'4' => "");
        fputcsv($output, $tableau_menu);
        fputcsv($output, $empty_tab);
        # Then loop through the rows
        foreach($rows as $row)
        {
            # Add the rows to the body
            fputcsv($output, $row);
        }
        # Exit to close the stream off
        //exit();
        return $name;
    }
    # Default to a failure
    return false;
}

/****************************************************
* --- Mise en Oeuvre des Fonctions en une seule --- *
*****************************************************/

/* Cette fonction permet :
	- De rechercher en base SQL les données CDR basé sur une date (format: "YYYY-MM-JJ HH-MM-SS")
	- De créer un fichier CSV basé sur les précédents données récoltées
	- D'envoyer un mail avec le fichier CSV
*/
function easyLaunch(
/* ------ DEBUT DES ARGUMENTS DE LA FONCTION ------ */

// Partie 0 : L'EXTENSION DE RECHERCHE
$monextension,
// Partie 1 : Ordre d'affichage des résultats
$tableau_affichage_final,		// Le tableau Final avec les champs souhaités
// Partie 3 : LE MAIL
$titre_mail,		// Le titre du mail
$destination_mail, 	// L'adresse de destination du mail
$contenu_mail,		// Le contenu du mail (attention aux caractères spéciaux)
// Partie 4 : SUPPLEMENTS
$month=null,	// Désignation du mois => -1 = mois précédent (paramètre non obligatoire, si non définit = mois courant)
$databaseSQL=null, 	// La base de données
$tableSQL=null,		// La table utilisée
$whereSQL=null 		// Permet de redéfinir la requete SQL après la partie WHERE

/* ------ FIN DES ARGUMENTS DE LA FONCTION ------ */
)
{
/* Appel de la fonction pour selectionner le mois */
	$result_func_month = get_month($month);
	// Récupération des valeurs dans des variables
	$previous_month = $result_func_month["month"]; 	// Contient le mois type "Janvier"
	$year = $result_func_month["year"];				// Contient l'année du mois sélectionné
	$digitMonth = $result_func_month["digit"];		// Contient le mois type "1"

/* Traitement du tableau final et préparation pour la requête SQL */
	$arguments_rqt_sql = null;
	foreach ($tableau_affichage_final as $key => $value) {
		// Si dans le tableau on retrouve DEFINEmonth, on modifie pour selectionner le mois souhaité:
		if (preg_match("/DEFINEmonth/", $value)) { $tableau_affichage_final[$key] = "DEFINEmonth".$previous_month;}
		// Si dans le tableau on retrouve NUMERO, alors on appel src et dst pour la requête sql:
		elseif (preg_match("/NUMERO/", $value)) { 
			$arguments_rqt_sql.= "src,";
			$arguments_rqt_sql.= "dst,";
		}else{
			$arguments_rqt_sql.= $value.",";
		}
	}
	// Retrait de la dernière virgule:
	$arguments_rqt_sql = substr($arguments_rqt_sql, 0,-1);

/* Préparation & exécution de la requête SQL */

	if($databaseSQL==null){$databaseSQL="asteriskcdrdb";}
	if($tableSQL==null){$tableSQL="cdr";}
	if ($whereSQL != null) { $sql = "SELECT $arguments_rqt_sql FROM $tableSQL WHERE $whereSQL";}
	else{
		if (DEBUG == true) { $limit = "LIMIT 0,10";}else{$limit = "";}
		$sql = "SELECT $arguments_rqt_sql
				FROM $tableSQL
				WHERE calldate LIKE(\"".$year."-".$digitMonth."-%\")
				AND 
				(src LIKE \"$monextension\" OR dst LIKE \"$monextension\")
				ORDER BY calldate DESC $limit;";
	}
	// function sql_request: (arg1: request, arg2: database)
	$tab_data_sql = sql_request($sql,$databaseSQL);
	// Traitement sql, préparation à la création du fichier CSV
	$tab_result = traitement_sql($tab_data_sql,$tableau_affichage_final, $monextension);

/* Création du fichier csv */
	$titre_mail_csv = str_replace(' ', '-', $titre_mail);
	$titre_mail_csv = 'Rapport-'.$previous_month.'-'.$titre_mail_csv;
	$return_csv = create_csv_file($tab_result,$titre_mail_csv,',');

/* Préparation & envoi du mail */
	$body = "Mail automatique:\n\n\nMois: ".$previous_month."\n\n\n".$contenu_mail;
	$file = $return_csv;
	$subject_email = $titre_mail_csv;
	if (DEBUG == false) {
		if(!send_email_with_attachment($destination_mail,$subject_email,$body,$file)){ goto ERROR;}
		else{
			echo "*********************\n";
			echo "\nSuccès, email envoyé!\n";
			echo "*********************\n\n";
			/* Log du résulat */
			syslog(LOG_NOTICE, "$_SERVER[\"PHP_SELF\"]: Fonction exécuté, email envoyé");
			exit(0);
		}
	}else{
		echo "*************************************\n";
		echo "\nSuccès, fin de la fonction DEBUG!\n";
		echo "*************************************\n\n";
		exit(0);
	}

ERROR:
syslog(LOG_ERR, "$_SERVER[\"PHP_SELF\"]: Erreur dans la fonction!");
exit(-1);
}

/***********************************
* --- # Appel de la Fonction # --- *
************************************/

/*
*	- Fonctionnement : -
*	
*	argument 1 : (*)N° d'extension
*	argument 2 : (*)Tableau d'affichage final (ordre)
*	argument 3 : (*)Email destinataires
*	argument 4 : (*)Titre fichier csv et email
*	argument 5 : Mois de sélection => -1, -2...
*	argument 6 : Base de données utilisé (défaut = asteriskcdrdb)
*	argument 7 : Table sql utilisé (défaut = cdr)
*	argument 8 : Rédéfinition de la requête sql (défaut recherche sur la date d'appel du mois et par rapport au numéro définit)
*
*	(*) Paramètres obligatoires
*/
/* Variable pour tester la fonction limité à 10 résultats sql & sans générer le mail */
define("DEBUG", true);

/* ACCUEIL CFPR DINARD */

easyLaunch(
"15300",
array(0 => 'dcontext',1 => 'calldate',2 => "DEFINEmonth-1",3 => 'duration',4 => 'NUMERO'),
"Accueil CFPR Dinard",
"josselin.rouveau@roullier.com",
"Vous trouverez ci-joint le rapport pour le Standard téléphonique de l'accueil CFPR de Dinard.",
"-1"
);


/**************************************
* Espace Commentaires / Modifications *
***************************************

Droit du fichier : apache2

***************************************/
?>
