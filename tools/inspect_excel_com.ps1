param(
    [Parameter(Mandatory = $true)]
    [string]$Path,

    [string]$SheetName = '',

    [int]$SheetIndex = 0,

    [int]$MaxRows = 20,

    [int]$MaxCols = 20
)

$ErrorActionPreference = 'Stop'

$excel = $null
$workbook = $null

try {
    $resolvedPath = (Resolve-Path $Path).Path
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    $workbook = $excel.Workbooks.Open($resolvedPath, 0, $true)

    if ([string]::IsNullOrWhiteSpace($SheetName) -and $SheetIndex -le 0) {
        $names = New-Object 'System.Collections.Generic.List[object]'
        foreach ($worksheet in $workbook.Worksheets) {
            [void]$names.Add([string]$worksheet.Name)
        }
        $names | ConvertTo-Json -Depth 4 -Compress
        exit 0
    }

    if ($SheetIndex -gt 0) {
        $worksheet = $workbook.Worksheets.Item($SheetIndex)
    }
    else {
        $worksheet = $workbook.Worksheets.Item($SheetName)
    }
    $usedRange = $worksheet.UsedRange
    $rowsLimit = [Math]::Min([int]$usedRange.Rows.Count, $MaxRows)
    $colsLimit = [Math]::Min([int]$usedRange.Columns.Count, $MaxCols)

    $result = New-Object 'System.Collections.Generic.List[object]'
    for ($row = 1; $row -le $rowsLimit; $row++) {
        $values = New-Object 'System.Collections.Generic.List[object]'
        for ($col = 1; $col -le $colsLimit; $col++) {
            $cell = $usedRange.Item($row, $col)
            $value = $cell.Text
            if ($value -eq '') {
                [void]$values.Add($null)
            } else {
                [void]$values.Add([string]$value)
            }
        }
        [void]$result.Add($values)
    }

    $result | ConvertTo-Json -Depth 8 -Compress
}
finally {
    if ($workbook -ne $null) {
        $workbook.Close($false)
    }
    if ($excel -ne $null) {
        $excel.Quit()
    }
}
