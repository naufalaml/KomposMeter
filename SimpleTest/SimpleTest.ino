#include <Wire.h>
#include <Adafruit_ADS1X15.h>

Adafruit_ADS1115 ads;

void setup() {
  Serial.begin(115200);
  delay(1000); // Beri waktu Serial Monitor siap
  
  Serial.println(F("\n========================================="));
  Serial.println(F("      ADS1115 SIMPLE DIAGNOSTIC TEST     "));
  Serial.println(F("========================================="));

  // 1. Inisialisasi I2C dengan pin pilihan Anda
  Serial.println(F("[I2C] Menginisialisasi I2C pada SDA=D1, SCL=D2..."));
  Wire.begin(D1, D2);
  
  // Menurunkan kecepatan I2C ke 50 kHz agar komunikasi lebih stabil 
  // pada kabel jumper/breadboard yang kurang bagus/longgar.
  Wire.setClock(50000); 
  delay(500); // Beri jeda agar tegangan pada jalur I2C stabil

  // 2. Scan I2C Bus untuk mendeteksi perangkat
  Serial.println(F("[I2C] Melakukan scan perangkat..."));
  byte error, address;
  int devicesFound = 0;
  byte detectedAddress = 0;

  for (address = 1; address < 127; address++) {
    Wire.beginTransmission(address);
    error = Wire.endTransmission();
    
    if (error == 0) {
      Serial.printf("[I2C] Terdeteksi perangkat pada alamat: 0x%02X\n", address);
      devicesFound++;
      // Cari alamat di rentang ADS1115 (0x48 - 0x4B) atau LCD (biasanya 0x27 / 0x3F)
      if (address >= 0x48 && address <= 0x4B) {
        detectedAddress = address;
      }
    }
  }

  if (devicesFound == 0) {
    Serial.println(F("[ERROR] Tidak ada perangkat I2C yang terdeteksi sama sekali!"));
    Serial.println(F("[ERROR] Cek kembali perkabelan, solderan, dan catu daya VCC/GND Anda."));
    while (1) { delay(1000); }
  }

  // 3. Inisialisasi ADS1115 berdasarkan alamat yang terdeteksi
  if (detectedAddress == 0) {
    Serial.println(F("[WARNING] Perangkat I2C terdeteksi, tapi TIDAK ADA modul ADS1115 (0x48 - 0x4B)!"));
    Serial.println(F("[WARNING] Mencoba inisialisasi paksa dengan alamat default 0x48..."));
    detectedAddress = 0x48;
  }

  Serial.printf("[ADS1115] Mencoba menghubungkan ke alamat: 0x%02X...\n", detectedAddress);
  if (!ads.begin(detectedAddress)) {
    Serial.println(F("[ERROR] ADS1115 GAGAL diinisialisasi!"));
    Serial.println(F("[Tips] Jika alamat terdeteksi tapi gagal init, pastikan pin ADDR terhubung mantap ke GND atau VCC (tidak melayang)."));
    while (1) { delay(1000); }
  }

  Serial.println(F("[SUCCESS] ADS1115 BERHASIL diinisialisasi!"));
  ads.setGain(GAIN_ONE); // ±4.096V
}

void loop() {
  // Membaca nilai dari Channel 0 (MQ-135) dan Channel 1 (Soil Moisture)
  int16_t adc0 = ads.readADC_SingleEnded(0);
  int16_t adc1 = ads.readADC_SingleEnded(1);

  // Konversi raw ADC ke Tegangan (dengan Gain GAIN_ONE, 1 bit = 0.125 mV)
  float volt0 = ads.computeVolts(adc0);
  float volt1 = ads.computeVolts(adc1);

  Serial.printf("A0 (MQ-135)     : Raw ADC = %5d | Volt = %.4f V\n", adc0, volt0);
  Serial.printf("A1 (Soil Moist) : Raw ADC = %5d | Volt = %.4f V\n", adc1, volt1);
  Serial.println(F("-------------------------------------------------"));

  delay(2000); // Baca setiap 2 detik
}
