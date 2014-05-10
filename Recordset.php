<?php
/*****
 * RECORDSET.PHP
 * A set of classes to reduce the amount of coding needed for building and
 * executing queries.
 * 
 * The Recordset class uses a Column class to define a field in the row/record.
 * Additional classes are derived from Column: Decimal, DateStamp, LongDateTime,
 * Password, ReadOnly, Reference, ShortDate, SubQuery.
 * 
 * To use: create an instance of Recordset, set its table property with the name 
 * of the table to query and add instances of Column to it. Or, derive a new 
 * class from Recordset and in its constructor set the table name and add the 
 * columns. Then create an instance to use. This approach has the advantage of 
 * allowing the addition of attributes and methods. 
 * 
 * See documentation for more information.
 * 
 * Terence Lein, 2014-04-25
 * tlein@optonline.net
 * 
 */

class Column {

  public $field;
  public $name = "";
  private $function;

  function __construct ($field, $name = "") {
    $this->field  = $field;
    if (strpos($name,".") !== FALSE) {
      throw new Exception ("Column::Column: Alias name cannot be qualified.");
    }
    $this->name   = $name;
  }
  
  function AddMessage ($text) {
      if(is_array($text)){
          $this->recordset->messages[]  = array_merge($this->recordset->messages,$text);
      }else{
          $this->recordset->messages[]  = $text;
      }
  }

  function GetName () {
    return ($this->name != "") ? $this->name : $this->field;
  }

  function Validate ($data) {
  }

  function Format ($data) {
    return $data;
  }
  
  function Parse ($data) {
      return $data;
  }

  function SetFunction($function="") {
    $this->function = $function;
  }

  function Prepare ($data) {
    return mysql_real_escape_string($data);
  }
}

class ShortDate extends Column {
  public $label;

  function __construct ($field, $name="", $label="") {
    parent::__construct ($field, $name);
    $this->label = ($label == "") ? $name : $label;
  }

  function Validate ($date) {
      if ($date != "") {
      if (!IsValidDate ($date)) {
        parent::AddMessage ($this->label . " is not a valid date.", "No");
        return false;
      }
    }
    return true;
  }

  function Format ($date) {
    if($date != "" && $date !== null){
      $part  = preg_split("/[-\s:]+/", $date);
      $part[0]  %= 100;
      $date   = ($part[0] == 0 && $part[1] == 0 && $part[2] == 0)
                ? ""
                : sprintf("%s/%s/%s", $part[1], $part[2], $part[0]);
      return $date;
    }
    return "";
  }

  function Prepare ($date) {
    return mysql_real_escape_string(ParseDate($date));
  }
}

class DateStamp extends ReadOnly {
    function Format ($date) {
        return LongDateTime::getLongDate($date);
    }
}

class LongDateTime extends Column {
  public $label;

  function __construct ($field, $name="", $label="") {
    parent::__construct ($field, $name);
    $this->label = ($label == "") ? $name : $label;
  }
  
  function getObject ($date) {
      try{
          $obj  = new DateTime($date, new DateTimeZone($_SESSION["User"]["TimeZone"]));
      }
      catch(Exception $e){
          $obj  = new DateTime("");
      }
      return $obj;
  }
  
  function getShortDate ($date) {
      $obj  = self::getObject($date);
      return $obj->format("m/d/y");
  }

  function getLongDate ($date) {
      $obj  = self::getObject($date);
      return $obj->format("Y-m-d");
  }

  function getTime ($date) {
      $obj  = self::getObject($date);
      return $obj->format("H:i:s");
  }

  function Validate ($date) {
      if ($date != "")  return;
      $errors   = self::getObject($date)->getLastErrors();
      if($errors["error_count"] > 0){
        parent::AddMessage($errors["warnings"]);
        parent::AddMessage($errors["errors"]);
      }
  }
  
  function Format ($date,$tz=false) {
    if($date != "" && $date !== null){
      $obj  = self::getObject($date);
      return $obj->format(sprintf("Y-m-d H:i:s%s",($tz) ? "P" : ""));
    }
    return "";
  }

  function Prepare ($date) {
    $obj  = self::getObject($date);
    $obj->setTimezone(new DateTimeZone("UTC"));
    return $obj->format("Y-m-d H:i:s");
  }
}

class Reference extends Column {
  public $linesize = 35;
  public $maxlines = 0;

  function __construct ($field, $max=0, $name="") {
    parent::__construct ($field, $name);
    $this->maxlines = $max;
  }

  function Prepare ($data) {
    $data   = preg_split("/[\n]/", br2nl($data));
      foreach($data as $line){
        if($line != ""){
          $formatted  .= trim($line) . "\n";
        }
      }
    return mysql_real_escape_string($formatted);
  }

  function Format ($data) {
    return nl2br ($data);
  }

  function Validate ($data) {
    $data   = preg_split("/[\n]/", br2nl($data));
      foreach($data as $line){
        if($line != ""){
          if($this->linesize > 0){
            if(strlen(trim($line)) > $this->linesize){
              parent::AddMessage(sprintf("A line in '%s' exceeds the maximum length of %d.",
                                 ($this->name != "") ? $this->name : $this->field,
                                 $this->linesize) );
            }
          }
          $count++;
        }
      }
      if($this->maxlines > 0 && $count > $this->maxlines){
        parent::AddMessage(sprintf("Field '%s' exceeds %d lines.",
                           self::GetName(),
                           $this->maxlines) );
      }
  }

}

class Decimal extends Column {
  public $decimals = 0;

  function __construct ($field, $decimals=0, $name="") {
    parent::__construct ($field, $name);
    $this->decimals = $decimals;
  }

  function Validate ($decimal) {
    if ($decimal != "") {
      if (!is_numeric ($decimal)) {
        parent::AddMessage ($decimal . " is not a valid decimal number.", "No");
      }
    }
  }
}

class Password extends Column {
    
    function Format ($data) {
        return "********";
    }
    
    function Prepare ($data) {
        return sprintf("'%s'",password_hash($data,PASSWORD_DEFAULT));
    }
}

class ReadOnly extends Column {};

class SubQuery extends ReadOnly {};

class Recordset {

  public $rows;
  public $cols;
  public $function  = "";
  public $where  = "";
  public $order  = "";
  public $table  = "";
  public $total  = 0;
  public $index  = -1;
  public $join   = "";
  public $rpc    = "";
  public $start;
  public $count;
  public $TimeStamp = false;

  // constructor loads a row set if given
  function __construct ($rows = NULL) {
    $this->rows = ($rows != NULL) ? $rows : array();
  }
  
  function SetRange ($start=0,$count=25){
      $this->start  = $start;
      $this->count  = $count;
  }

  // set field
  function SetFieldByName ($field,$name) {
    if(is_array($this->cols))
    foreach($this->cols as $c => $col){
      if($col->name == $name){
        $col->field = $field;
        $this->cols[$c] = $col;
      }
    }
  }

  function Begin () {
    self::Query("begin");
  }

  function Commit () {
    self::Query("commit");
  }

  function setRemote ($host,$server,$port="") {
    $this->rpc  = new RPC($host,$server,$port);
  }

  function SetFilter ($where) {
    $this->where  = $where;
  }

  function SetSort ($order) {
    $this->order  = $order;
  }

  function SetJoin ($join) {
    if (stripos($this->table," join ") !== FALSE ||
        strpos($this->table,",") !== FALSE) {
      throw new Exception ("Recordset::SetJoin: Conflicting joins");
    }
    $this->join  = $join;
  }

  function AddColumn ($col) {
    if (!($col instanceof Column)) {
      throw new Exception ("Recordset::AddColumn: object is not of class \"Column\"");
    }
    $name = $col->GetName();
    $col->table        = $this->table;
    $col->recordset    = $this;
    $this->cols[$name] = $col;
    if($col instanceof DateStamp)   $this->TimeStamp = true;
  }

  function GetFieldName ($name) {
    if (($i = strpos ($name, ".")) !== FALSE) {
      return sprintf ("`%s`.`%s`",
                      substr($name,0,$i),
                      substr($name,$i+1));
    } else {
      return sprintf ("`%s`", $name);
    }
  }

  function GetCount () {
    if($this->rpc != ""){
      $Request["Table"]   = $this->table;
      $Request["Filter"]  = $this->where;
      $CountRow["Count"]  = $this->rpc->Request("getCount",$Request);
    } else {
      $Query        = sprintf("select count(*) from %s%s",
                              $this->table,
                              ($this->where != "") ? " where " . $this->where : "");
      $CountQuery   = self::Query($Query);
      $CountRow     = mysql_fetch_row($CountQuery);
    }
    return $CountRow[0];
  }

  function Select () {
    ($this->rpc == "")  ? self::LocalSelect() : self::RemoteSelect();
    $this->index  = (count($this->rows) > 0) ? 0 : -1;

    for ($i = 0; $i < count($this->rows); $i++) {
      foreach ($this->cols as $name => $col) {
        $this->rows[$i][$name] = $col->Format($this->rows[$i][$name]);
      }
    }
    return ($this->index > -1) ? $this->rows[0] : false;
  }

  function LocalSelect () {

    $Query        = $this->MakeSelect ();
    $SetQuery     = $this->Query ($Query);
    $this->total  = mysql_num_rows($SetQuery);
    for ($this->rows = array(); $row = mysql_fetch_assoc ($SetQuery);) {
      $this->rows[] = $row;
    }
  }
  
  function RemoteSelect () {
    $request["Table"] = $this->table;
    foreach ($this->cols as $col) {
      $field[0] = ($col instanceof SubQuery)
                    ? $col->field
                    : $this->GetFieldName($col->field);
      $field[1] = $col->name;
      $request["FieldList"][] = $field;
    }
    $request["Filter"]  = $this->where;
    $request["Order"]   = $this->order;
    $this->total  = ($this->rows = $this->Request("select", $request))
                      ? count($this->rows) : 0;
  }

  function MakeSelect () {
    $this->query  = "";
    $Query  = "select SQL_CALC_FOUND_ROWS ";
    if (!is_array($this->cols)) {
      $select  = "*";
    } else {
      $select = "";
      foreach ($this->cols as $col) {
        $select  .= ($col instanceof SubQuery)
                  ? sprintf ("%s%s %s",
                            ($select != "") ? ", " : "",
                            $col->field,
                            ($col->name != "") ? "as " . $col->name . "" : "")
                  : sprintf ("%s%s.%s %s",
                            ($select != "") ? ", " : "",
                            $this->table,
                            $this->GetFieldName($col->field),
                            ($col->name != "") ? " as " . $col->name . "" : "");
      }
    }
    
    if($this->start != "" || $this->count != ""){
        $limit  = sprintf(" limit %s%d", 
                            ($this->start != "") ? $this->start.", " : "",
                            ($this->count == "") ? $this->count : 25);
    }
    $Query  = sprintf ("select %s%s%s%s%s%s",
                        $select,
                        ($this->table != "")
                          ? " from ".$this->table
                          : "",
                        ($this->join != "")
                          ? " left join " . $this->join
                          : "",
                        ($this->where != "")
                          ? " where " . $this->where
                          : "",
                        ($this->order != "")
                          ? " order by " . $this->order
                          : "",
                        $limit);
    return $Query;
  }

  function Insert (&$row) {
    if ($this->IsJoin()) {
      throw new Exception ("Recordset::Insert: a set with a table join is read-only.");
    }
    
    // create field and value lists
    $fields = "";
    $values = "";
    foreach ($this->cols as $name => $col) {
      if (!($col instanceof ReadOnly)) {
          
        // append to field list
        $fields .= sprintf ("%s%s",
                            ($fields != "") ? ", " : "",
                            $this->GetFieldName($col->field));
        
        // append to values list
        if(!isset($row[$name]) || $row[$name] == ""){
          $values .= sprintf ("%sNULL",
                              ($values != "") ? ", " : "");
        } else {
          $values .= sprintf ("%s'%s'",
                              ($values != "") ? ", " : "",
                              $col->Prepare($row[$name]) );
        }
      }
    }
    
    // date/time stamp 
    if($this->TimeStamp){
        $fields .= sprintf ("%sCreated, Updated",
                            ($fields != "") ? ", " : "");
        $values .= sprintf ("%sCURRENT_DATE, CURRENT_DATE",
                            ($values != "") ? ", " : "");
    }
    
    // create query
    $Query  = sprintf ("insert into %s (%s) values (%s)",
                       $this->table,
                       $fields,
                       $values);
    if($this->Query ($Query)){
        $row["ID"]  = mysql_insert_id ();
        $this->wasUpdated   = ($row["ID"] != "");
        return $row["ID"];
    } else {
        return false;
    }
  }

  function Delete ($id = "") {
    if ($id == "") {
      return false;
    }
    if ($this->IsJoin()) {
      throw new Exception ("Recordset::Delete: a set with a table join is read-only.");
    }
    $Query  = sprintf ("delete from %s where %s",
                        $this->table,
                        $id);
    return ($this->Query ($Query))
            ? mysql_affected_rows() > 0
            : false;
  }

  function Update ($row, $id) {
    $this->wasUpdated   = false;
    if ($id == "") {
      return false;
    }
    if ($this->IsJoin()) {
      throw new Exception ("Recordset::Update: a set with a table join is read-only.");
    }
    $changes  = "";
    foreach ($this->cols as $name => $col) {
      if (!($col instanceof ReadOnly)) {
        if(!isset($row[$name]) || $row[$name] == ""){
          $changes  .= sprintf ("%s%s = NULL",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field) );
        } else 
        if($col instanceof Password){
          $changes  .= sprintf ("%s%s = %s",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field),
                                $col->Prepare($row[$name]) );
        } else {
          $changes  .= sprintf ("%s%s = '%s'",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field),
                                $col->Prepare($row[$name]) );
        }
      }
    }
    
    // date/time stamp 
    if($this->TimeStamp){
        $changes .= sprintf ("%sUpdated = CURRENT_DATE",
                             ($changes != "") ? ", " : "");
    }
    
    $Query  = sprintf ("update %s set %s where %s",
                        $this->table,
                        $changes,
                        $id);
    $this->wasUpdated   = $this->Query ($Query);
    return $this->wasUpdated ? mysql_affected_rows() : false;
  }
  
  function Replace ($row) {
    $this->wasUpdated   = false;
    if ($id == "") {
      return false;
    }
    if ($this->IsJoin()) {
      throw new Exception ("Recordset::Replace: a set with a table join is read-only.");
    }
    $changes  = "";
    foreach ($this->cols as $name => $col) {
      if (!($col instanceof ReadOnly)) {
        if(!isset($row[$name])){
          $changes  .= sprintf ("%s%s = NULL",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field) );
        } else 
        if($col instanceof Password){
          $changes  .= sprintf ("%s%s = %s",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field),
                                $col->Prepare($row[$name]) );
        } else {
          $changes  .= sprintf ("%s%s = '%s'",
                                ($changes != "") ? ", " : "",
                                $this->GetFieldName($col->field),
                                $col->Prepare($row[$name]) );
        }
      }
    }
    $Query  = sprintf ("replace %s set %s",
                        $this->table,
                        $changes,
                        $id);
    $this->wasUpdated       = $this->Query ($Query);
    if($this->Query ($Query)){
        $row["ID"]          = mysql_insert_id ();
        $this->wasUpdated   = ($row["ID"] != "");
        return $row["ID"];
    } else {
        return false;
    }
  }
  
  function WasUpdated () {return $this->wasUpdated;}
  
  function HasChanged ($data) {
    if(!is_array($data))  throw new Exception("Recordset::HasChanged - row was not provided.");
    $id   = $data["ID"];
    if($this->rows != ""){
        foreach($this->rows as $row){
            if($row["ID"] == $id){
                return !self::CompareRows($row,$data);
            }
        }
        return true;
    }
    if($id == ""){
        return true;
    }
    $query    = self::Query(sprintf("select * from `%s` where ID = %d",
                              $this->table,
                              $id) );
    $row  = mysql_fetch_assoc($query);
    return !self::CompareRows($row,$data);
  }
  
  function CompareRows($old,$new) {
      if(!is_array($old)){
          if(!is_array($new)){
              throw new Exception ("Recordset::CompareRows - no rows to compare");
          }
          return false;
      } else 
      if(!is_array($new)){
          return false;
      }
      foreach($old as $key => $data){
          if($new[$key] != $data){
              return false;
          }
      }
      return true;
  }

  function IsJoin () {
    return (stripos($this->table," join ") !== FALSE ||
             strpos($this->table,",") !== FALSE ||
             $this->join != "");
  }

  function AppendBlankRows ($count) {
    for ($i = 0; $i < $count; $i++) {
      $this->rows[] = array ();
    }
  }

  function Query ($Query) {
    $_SESSION["QueryHistory"][] = $Query;
    $result = mysql_query ($Query);
    if (!$result) {
      throw new Exception (mysql_error() . ": " . $Query);
    }
    return $result;
  }

  function Validate ($row) {
    $count  = MessageCount();
    foreach($this->cols as $col){
      $col->Validate ($row[$col->GetName()]);
    }
    return ($count == MessageCount());
  }

  function Quote ($data) {
    return sprintf("'%s'",mysql_real_escape_string($data));
  }

  function QuoteLike ($data) {
    return sprintf("'%%%s%%'",mysql_real_escape_string($data));
  }

  function GetMaximumValue ($col) {
    $Query  = sprintf("select %s as MaximumValue from %s order by %s desc limit 1",
                      $col, $this->table, $col );
    $MaxQuery = self::Query($Query);
    return ($MaxRow   = mysql_fetch_assoc($MaxQuery)) ? $MaxRow["MaximumValue"] : "";
  }

  function SetFunction($function="") {
    $this->function = $function;
  }

}

?>
