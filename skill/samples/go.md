# Go — MyOTP.App

Go 1.18+. Uses only `net/http` and `encoding/json` from the standard library.
Set `MYOTP_API_KEY` in your environment.

```go
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

const baseURL = "https://api.myotp.app"

var httpClient = &http.Client{Timeout: 10 * time.Second}

type GenerateResp struct {
	MessageID string  `json:"message_id"`
	Status    string  `json:"status"`
	ExpiresAt string  `json:"expires_at"`
	Cost      float64 `json:"cost"`
}

type VerifyResp struct {
	Status  string `json:"status"`
	Reason  string `json:"reason,omitempty"`
	Message string `json:"message"`
}

func post(path string, body any, out any) error {
	buf, _ := json.Marshal(body)
	req, _ := http.NewRequest("POST", baseURL+path, bytes.NewReader(buf))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-API-Key", os.Getenv("MYOTP_API_KEY"))
	res, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(res.Body)
	if res.StatusCode >= 400 {
		return fmt.Errorf("%s %d: %s", path, res.StatusCode, string(raw))
	}
	return json.Unmarshal(raw, out)
}

// channel: "sms" (default), "whatsapp", or "telegram"
func GenerateOTP(phone, channel string) (*GenerateResp, error) {
	r := &GenerateResp{}
	err := post("/generate_otp", map[string]string{
		"phone_number": phone,
		"channel":      channel,
	}, r)
	return r, err
}

// verify_otp returns 200 even when status is "failed" — inspect r.Status.
func VerifyOTP(phone, otp string) (*VerifyResp, error) {
	r := &VerifyResp{}
	err := post("/verify_otp", map[string]string{
		"phone_number": phone,
		"otp":          otp,
	}, r)
	return r, err
}

func main() {
	sent, err := GenerateOTP("14155551234", "sms")
	if err != nil {
		panic(err)
	}
	fmt.Println("message_id:", sent.MessageID)
	// ... user enters code ...
	v, _ := VerifyOTP("14155551234", "123456")
	if v.Status == "success" {
		fmt.Println("verified")
	} else {
		fmt.Println("failed:", v.Reason)
	}
}
```
