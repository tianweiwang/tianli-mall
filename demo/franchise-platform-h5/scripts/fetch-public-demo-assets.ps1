$ErrorActionPreference = 'Stop'

$assetDir = Join-Path $PSScriptRoot '..\public\products'
New-Item -ItemType Directory -Force -Path $assetDir | Out-Null

$products = @(
  @{
    Id = 4
    File = 'ipad-mini.png'
    Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/5b64e6584c06020347a5fa58af79d42a.png'
  },
  @{
    Id = 3
    File = 'jeep-watch.png'
    Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/5ae4f6a3f8bf153beb849e02e14b5c40.png'
  },
  @{
    Id = 1
    File = 'xiaomi-civi.png'
    Url = 'http://127.0.0.1:8011/uploads/attach/2023/02/20230213/3b570808fc3593aa51b2a94ceaba3e6f.png'
  }
)

foreach ($product in $products) {
  $target = Join-Path $assetDir $product.File
  Invoke-WebRequest -UseBasicParsing -Uri $product.Url -OutFile $target
  Write-Host "Downloaded product $($product.Id) to $target"
}
