<?php
$f = dirname(__FILE__)."/conf.php";
if (!file_exists( $f )) die("
  Pas de fichier de configuration, renommez _conf.php en conf.php.
");
$conf = include( $f );


Xmarc2sql::connect( $conf['sqlite'] );
Xmarc2sql::glob( $conf['srcglob'] );

class Xmarc2sql {
  /** Table de prénoms (pour reconnaître le sexe), chargée depuis given.php */
  static $given;
  /** Table de caractères pour mise à l’ASCII, chargée depuis frtr.php */
  static $frtr;
    /** connection */
  static private $_pdo;
  /** où sommes nous ? */
  static private $_marc;
  /** texte */
  static private $_text;
  /** parseur */
  static $file;
  /** colonnes */
  static private $_cols = array(
    "document" => array(
      "ark",
      "title",
      "date",
      "year",
      "place",
      "publisher",
      "dewey",
      "lang",
      "type",
      "description",
      "pages",
      "size",
      "byline",
      "bysort",
      "person",
      "birthyear",
      "deathyear",
      "posthum",
      "hasgall",
      "id",
    ),
    "contribution" => array(
      "document",
      "person",
      "role",
      "writes",
    ),
    "person" => array(
      "family",
      "given",
      "sort",
      "gender",
      "date",
      "birthyear",
      "deathyear",
      "id",
    ),
    "gallica" => array(
      "document",
      "ark",
      "title",
      "id",
    )
  );
  /** queries */
  static private $_q;
  /** enregistrements */
  static private $_rec;

  static function glob( $glob )
  {
    self::$given = include(dirname(__FILE__).'/lib/given.php');
    self::$frtr = include( dirname( __FILE__ )."/lib/frtr.php" );
    self::$frtr[' ']='';
    self::$_q['document'] = self::$_pdo->prepare(
      "INSERT INTO document (".implode(", ", self::$_cols['document'] ).") VALUES (".rtrim(str_repeat("?, ", count( self::$_cols['document'] )), ", ").");"
    );
    self::$_q['title'] = self::$_pdo->prepare( "INSERT INTO title ( docid, text ) VALUES ( ?, ? )");
/*
<mxc:datafield tag="700" ind1=" " ind2=" ">
  <mxc:subfield code="3">11900134</mxc:subfield>
  <mxc:subfield code="w">0  b.....</mxc:subfield>
  <mxc:subfield code="a">Diderot</mxc:subfield>
  <mxc:subfield code="m">Denis</mxc:subfield>
  <mxc:subfield code="d">1713-1784</mxc:subfield>
  <mxc:subfield code="4">0360</mxc:subfield>
</mxc:datafield>
*/
    self::$_q['contribution'] = self::$_pdo->prepare(
      "INSERT INTO contribution (".implode(", ", self::$_cols['contribution'] ).") VALUES (".rtrim(str_repeat("?, ", count( self::$_cols['contribution'] )), ", ").");"
    );
    self::$_q['person'] = self::$_pdo->prepare(
      "INSERT INTO person (".implode(", ", self::$_cols['person'] ).") VALUES (".rtrim(str_repeat("?, ", count( self::$_cols['person'] )), ", ").");"
    );
    self::$_q['gallica'] = self::$_pdo->prepare(
      "INSERT INTO gallica (".implode(", ", self::$_cols['gallica'] ).") VALUES (".rtrim(str_repeat("?, ", count( self::$_cols['gallica'] )), ", ").");"
    );
    self::$_q['perstest'] = self::$_pdo->prepare( "SELECT * FROM person WHERE id = ?" );
    self::$_pdo->beginTransaction();
    $i = 0;
    foreach ( glob( $glob ) as $file ) {
      $i++;
      // echo "$i \r";
      self::$file = $file;
      self::_file( $file );
    }
    self::$_pdo->commit();
    self::$_pdo->exec( "
-- date des contributions (optimisation)
UPDATE contribution SET
  date=( SELECT year FROM document WHERE document=document.id)
;
-- nombre de documents
UPDATE person SET
  docs=( SELECT count(*) FROM contribution WHERE person=person.id AND writes = 1 )
;
UPDATE person SET writes=1 WHERE docs > 0;
-- les morts
UPDATE person SET
  posthum=( SELECT count(*) FROM contribution WHERE person=person.id AND writes = 1 AND date > deathyear+1 ),
  anthum=( SELECT count(*) FROM contribution WHERE person=person.id AND writes = 1 AND date <= deathyear+1 )
  WHERE writes=1 AND deathyear > 0
;
-- Homère
UPDATE person SET
  posthum=( SELECT count(*) FROM contribution WHERE person=person.id AND writes = 1 )
  WHERE birthyear IS NULL AND deathyear IS NULL
;
-- les vivants
UPDATE person SET
  anthum=( SELECT count(*) FROM contribution WHERE person=person.id AND writes = 1 )
  WHERE birthyear > 0 AND deathyear IS NULL
;
-- date d’édition après mort de l’auteur principal ?
UPDATE document SET
  posthum=( date > deathyear+1 )
;
      " );
  }

  private static function _file( $file )
  {
    $data = file_get_contents($file);
    $parser = xml_parser_create();
    // use case-folding so we are sure to find the tag in $map_array
    xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, false );
    xml_set_element_handler( $parser, "Xmarc2sql::_open", "Xmarc2sql::_close" );
    xml_set_character_data_handler( $parser, "Xmarc2sql::_text" );
    if ( !xml_parse( $parser, $data, true ) ) {
      die( sprintf(
        "XML error: %s at line %d",
        xml_error_string( xml_get_error_code( $parser ) ),
        xml_get_current_line_number( $parser ))
      );
    }
    xml_parser_free( $parser );
  }

  private static function _open( $parser, $name, $atts )
  {
    self::$_text = "";
    if ( $name == "mxc:record" ) {
      self::$_rec['document'] = array_fill_keys ( self::$_cols['document'], null );
      // id="ark:/12148/cb30089185p"
      $ark = substr( $atts['id'], 11);
      self::$_rec['document']['ark'] = $ark;
      self::$_rec['document']['id'] = substr($ark, 2, -1);
    }
    if ( $name == "mxc:controlfield" ) {
      self::$_marc = array( $atts['tag'] );
    }
    if ( $name == "mxc:datafield" ) {
      self::$_marc = array();
      self::$_marc[0] = $atts['tag'];
      if ( self::$_marc[0] == 937 ) {
        self::$_rec['gallica'] =  array_fill_keys ( self::$_cols['gallica'], null );
        self::$_rec['gallica']['document'] = self::$_rec['document']['id'];
      }
      if ( self::$_marc[0] == 100 || self::$_marc[0] == 700 ) {
        self::$_rec['person'] =  array_fill_keys ( self::$_cols['person'], null );
        self::$_rec['contribution'] =  array_fill_keys ( self::$_cols['contribution'], null );
        self::$_rec['contribution']['document'] = self::$_rec['document']['id'];
      }
      if ( self::$_marc[0] == 110 || self::$_marc[0] == 710 ) {
        self::$_rec['org'] = array();
      }
    }
    else if ( $name == "mxc:subfield" ) {
      self::$_marc[1] = $atts['code'];
    }
  }

  private static function _close( $parser, $name )
  {
    if ( $name == 'mxc:subfield' ) {
      if ( self::$_marc == array( 41, "a" ) ) self::$_rec['document']['lang'] = self::$_text;
      if ( self::$_marc == array( 100, "3" ) || self::$_marc == array( 700, "3" ) ) {
        self::$_rec['person']['id'] = self::$_text;
        self::$_rec['contribution']['person'] = self::$_text;
      }
      if ( self::$_marc == array( 100, "4" ) || self::$_marc == array( 700, "'3'" ) ) {
        $role = 0+self::$_text;
        self::$_rec['contribution']['role'] = $role;
        if ( $role == 70 || $role == 71 || $role == 72 || $role == 73 || $role == 980 || $role == 990 ) self::$_rec['contribution']['writes'] = 1;
      }
      if ( self::$_marc == array( 100, "a" ) || self::$_marc == array( 700, "a" ) ) self::$_rec['person']['family'] = self::$_text;
      if ( self::$_marc == array( 100, "d" ) || self::$_marc == array( 700, "d" ) ) {
        self::$_rec['person']['date'] = self::$_text;
        $moins = "";
        if ( strpos( self::$_text, "av." ) ) $moins="-";
        if(preg_match("@(\d+)\??-@", self::$_text, $matches)) {
          self::$_rec['person']['birthyear'] = $moins.$matches[1];
        }
        if(preg_match("@-(\d\d\d\d)@", self::$_text, $matches)) {
          self::$_rec['person']['deathyear'] = $moins.$matches[1];
        }
      }
      if ( self::$_marc == array( 100, "m" ) || self::$_marc == array( 700, "m" ) ) {
        self::$_rec['person']['given'] = self::$_text;
      }
      if ( self::$_marc == array( 110, "3" ) || self::$_marc == array( 710, "3" ) ) {
        self::$_rec['org']['id'] = self::$_text;
      }
      if ( self::$_marc == array( 110, "4" ) || self::$_marc == array( 710, "4" ) ) {
        self::$_rec['org']['role'] = self::$_text;
      }
      if (
           self::$_marc == array( 110, "a" ) || self::$_marc == array( 710, "a" )
        || self::$_marc == array( 110, "c" ) || self::$_marc == array( 710, "c" )
      ) {
        if (!isset( self::$_rec['org']['name'] ) ) self::$_rec['org']['name']= self::$_text;
        else self::$_rec['org']['name'] = ", ".self::$_text;
      }
      if ( self::$_marc == array( 245, "a" ) ) self::$_rec['document']['title'] = self::$_text;
      if ( self::$_marc == array( 245, "d" ) ) self::$_rec['document']['type'] = self::$_text;
      if ( self::$_marc == array( 260, "a" ) ) self::$_rec['document']['place'] = self::$_text;
      if ( self::$_marc == array( 260, "c" ) ) self::$_rec['document']['publisher'] = self::$_text;
      if ( self::$_marc == array( 260, "d" ) ) {
        self::$_rec['document']['date'] = self::$_text;
        if ( preg_match( '@(\d{1,4})@', self::$_text, $matches) ) {
          self::$_rec['document']['year'] = $matches[0];
        }
        else if ( strpos( strtolower( self::$_text ), "s. d." ) !== false || strpos( strtolower(self::$_text), "s.d." ) !== false );
        else if ( preg_match( '@[Aa]n ([IVX]+)@', self::$_text, $matches ) )  {
          $rep = array( "I"=>1793, "II"=>1794, "III"=>1795, "IV"=>1796, "V"=>1797, "VI"=>1798, "VII"=>1799, "VIII"=>1800, "IX"=>1801, "X"=>1802, "XI"=>1803, "XII"=>1804, "XIII"=>1805, "XIV"=>1805 );
          if ( isset($rep[$matches[1]]) ) self::$_rec['document']['year'] = $rep[$matches[1]];
        }
        else {
          echo self::$file.' 260$d date ? '.self::$_text."\n";
        }
      }
      if ( self::$_marc == array( 280, "a" ) ) {
        self::$_rec['document']['description'] = self::$_text;
        preg_match_all( '/([0-9]+)(-[0-9IVXLC]+)? [pf]\./', self::$_text, $matches, PREG_PATTERN_ORDER );
        if ( count($matches[1]) > 0 ) {
          self::$_rec['document']['pages'] = 0;
          foreach( $matches[1] as $p ) self::$_rec['document']['pages'] += $p;
        }
        else if ( preg_match( "/ pièce /u", self::$_text ) ) {
          self::$_rec['document']['pages'] = 1;
        }
      }
      if ( self::$_marc == array( 680, "a" ) ) self::$_rec['document']['dewey'] = self::$_text;
      if ( self::$_marc == array( 937, "j" ) ) {
        // ark:/12148/bpt6k5655164n
        if (preg_match( '@(bpt6k([0-9]+).)@', self::$_text, $matches) ) {
          self::$_rec['gallica']['ark'] = $matches[1];
          self::$_rec['gallica']['id'] = $matches[2];
        }
        else if( strrpos( self::$_text, "ark:/12148/btv") === 0); // cartes et plans
        else {
          echo self::$file.' 937$j Lien gallica ? '.self::$_text."\n";
        }
      }
      if ( self::$_marc == array( 937, "k" ) ) self::$_rec['gallica']['title'] = self::$_text;
    }
    if ( $name == 'mxc:controlfield' ) {
      if ( self::$_marc[0] == 8 ) {
        if (preg_match( '@ (\d\d\d\d) @', self::$_text, $matches ) ) self::$_rec['document']['year'] = $matches[1];
        if (preg_match( '@ ([a-z][a-z])([a-z][a-z][a-z]) @', self::$_text, $matches ) ) self::$_rec['document']['lang'] = $matches[2];
      }
    }
    if ( $name == 'mxc:datafield' ) {
      if ( self::$_marc[0] == 100 || self::$_marc[0] == 700 ) {
        if ( self::$_rec['person']['date'] && strpos(self::$_rec['person']['date'], '.') === false && !self::$_rec['person']['birthyear'] ) {
          print_r( self::$_rec['person'] );
        }
        // auteur principal ?
        if ( !self::$_rec['document']['byline'] ) {
          self::$_rec['document']['person'] = self::$_rec['person']['id'];
          self::$_rec['document']['birthyear'] = self::$_rec['person']['birthyear'];
          self::$_rec['document']['deathyear'] = self::$_rec['person']['deathyear'];
        }
        // ajouter à la ligne auteur
        if ( self::$_rec['document']['byline'] ) self::$_rec['document']['byline'] .= " ; ";
        self::$_rec['document']['byline'] .= self::$_rec['person']['family'];
        if ( self::$_rec['person']['given'] ) self::$_rec['document']['byline'] .= ", ".self::$_rec['person']['given'];
        if ( self::$_rec['person']['given'] ) {
          $key = mb_strtolower( self::$_rec['person']['given'] );
          if ( isset( self::$given[$key] ) ) self::$_rec['person']['gender'] = self::$given[$key];
        }
        self::$_rec['person']['sort'] = strtr( self::$_rec['person']['family'].self::$_rec['person']['given'], self::$frtr );
        // record
        self::$_q['perstest']->execute( array( self::$_rec['person']['id'] ) );
        if ( !self::$_q['perstest']->fetch() ) {
          self::$_q['person']->execute( array_values( self::$_rec['person'] ) );
        }
        self::$_q['contribution']->execute( array_values( self::$_rec['contribution'] ) );
      }
      if ( self::$_marc[0] == 110 || self::$_marc[0] == 710 ) {
        // ajouter à la ligne auteur
        if ( self::$_rec['document']['byline'] ) self::$_rec['document']['byline'] .= " ; ";
        self::$_rec['document']['byline'] .= self::$_rec['org']['name'];
      }
      if ( self::$_marc[0] == 937 ) {
        // il ya des champs 937 sans lien gallica
        if( self::$_rec['gallica']['id'] ) {
          self::$_rec['document']['hasgall'] = 1;
          try {
            self::$_q['gallica']->execute( array_values( self::$_rec['gallica'] ) );
          }
          catch ( Exception $e ) {
            // pb de doublons dans les notices.
          }
        }
      }
    }
    if ( $name == 'mxc:record' ) {
      // (S. l. n. d.)
      // if ( !self::$_rec['document']['year'] && !self::$_rec['document']['date'] ) print_r(self::$_rec['document']);
      if ( self::$_rec['document']['byline'] ) self::$_rec['document']['bysort'] =  strtr( self::$_rec['document']['byline'], self::$frtr );
      // 656 notices n'ont pas de version numérisée (vérifié au catalogue BNF)
      if ( self::$_rec['document']['hasgall'] ) {
        self::$_q['document']->execute( array_values( self::$_rec['document'] ) );
        self::$_q['title']->execute( array( self::$_rec['document']['id'], self::$_rec['document']['title'] ) );
      }
    }
  }

  private static function _text( $parser, $data )
  {
    self::$_text .= $data;
  }

  /**
   * Connexion à la base de données
   */
  static function connect( $sqlfile, $create=false )
  {
    $dsn = "sqlite:" . $sqlfile;
    if($create && file_exists($sqlfile) ) unlink( $sqlfile );
    // create database
    if (!file_exists($sqlfile)) { // if base do no exists, create it
      if (!file_exists($dir = dirname($sqlfile))) {
        mkdir($dir, 0775, true);
        @chmod($dir, 0775);  // let @, if www-data is not owner but allowed to write
      }
      self::$_pdo = new PDO($dsn);
      self::$_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
      @chmod($sqlfile, 0775);
      self::$_pdo->exec( file_get_contents( dirname(__FILE__)."/marc.sql" ) );
      return;
    }
    else {
      // echo getcwd() . '  ' . $dsn . "\n";
      // absolute path needed ?
      self::$_pdo = new PDO($dsn);
      self::$_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
  }
}

?>
