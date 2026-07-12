<?php session_start(); 
include("../config.php"); 
if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') 
{ 
header("Location: ../login.php"); 
exit(); 
} 
?> 
<!DOCTYPE html> 
<html> 
<head> 
<title>Reports Module</title> 
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> 
<style> 
body{ background:#f1f5f9; 
} 
.report-box
{ 
width:500px; 
margin:auto; 
margin-top:120px; 
background:white; 
padding:40px; 
border-radius:20px; 
text-align:center; 
box-shadow:0px 2px 10px rgba(0,0,0,0.1); 
} 
</style> 
</head> 
<body>
    <br> 
    <a href="dashboard.php" class="btn btn-primary" style="border-radius:8px;margin-bottom:15px;"> ← Back </a> 
    <div class="report-box"> 
    <h1 class="mb-4"> 
    Reports Module 
    </h1> 
    <form method="GET" action="export_report.php"> 
    <div class="mb-3"> <select name="division" class="form-select" required> 
    <option value=""> Select Division </option> 
    <option value="SY-1">SY-1</option> 
    <option value="SY-2">SY-2</option> 
    <option value="SY-3">SY-3</option> 
    <option value="SY-4">SY-4</option> 
    <option value="SY-5">SY-5</option> 
    <option value="SY-6">SY-6</option> 
    <option value="SY-7">SY-7</option> 
    <option value="SY-8">SY-8</option> 
    <option value="SY-9">SY-9</option> 
    <option value="SY-10">SY-10</option> 
    <option value="SY-11">SY-11</option> 
    <option value="SY-12">SY-12</option> 
    <option value="SY-13">SY-13</option> 
    <option value="SY-14">SY-14</option> 
    <option value="SY-15">SY-15</option> 
    <option value="SY-16">SY-16</option> 
    <option value="SY-17">SY-17</option> 
    <option value="SY-18">SY-18</option> 
    <option value="SY-19">SY-19</option> 
    <option value="SY-20">SY-20</option> 
    <option value="SY-21">SY-21</option> 
    <option value="SY-22">SY-22</option> 
    </select> 
    </div> 
    <button type="submit" class="btn btn-primary"> View Report 
    </button> 
</form> 
</div> 
</body>