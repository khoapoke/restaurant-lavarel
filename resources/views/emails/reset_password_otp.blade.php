<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mã xác thực đặt lại mật khẩu</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #333333; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f4f6f8; padding: 30px 15px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" width="100%" style="max-width: 560px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #e5e7eb;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700; letter-spacing: 0.5px;">
                                T-ResMK Ẩm thực đường phố hiện đại
                            </h1>
                            <p style="margin: 6px 0 0; color: #94a3b8; font-size: 13px;">Hệ thống đặt món & ẩm thực trực tuyến</p>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 30px 25px;">
                            <p style="font-size: 16px; margin: 0 0 16px; color: #1e293b;">
                                Xin chào <strong>{{ $recipientName }}</strong>,
                            </p>
                            <p style="font-size: 15px; margin: 0 0 20px; color: #475569;">
                                Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn. Vui lòng sử dụng mã xác thực (OTP) dưới đây để tiếp tục quá trình tạo mật khẩu mới:
                            </p>

                            <!-- OTP Box -->
                            <div style="background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 10px; padding: 22px; text-align: center; margin: 25px 0;">
                                <span style="display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; color: #64748b; margin-bottom: 8px;">
                                    MÃ XÁC THỰC CỦA BẠN
                                </span>
                                <span style="display: inline-block; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #0f172a; font-family: 'Courier New', Courier, monospace;">
                                    {{ $otp }}
                                </span>
                                <p style="margin: 10px 0 0; font-size: 13px; color: #e11d48; font-weight: 500;">
                                    ⏱️ Mã này có hiệu lực trong vòng <strong>{{ $expireMinutes }} phút</strong>.
                                </p>
                            </div>

                            <!-- Security Warning -->
                            <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 6px; margin-bottom: 25px;">
                                <p style="margin: 0; font-size: 13px; color: #92400e; line-height: 1.5;">
                                    <strong>Lưu ý bảo mật:</strong> Tuyệt đối không chia sẻ mã này cho bất kỳ ai, kể cả nhân viên nhà hàng. Nếu bạn không yêu cầu đặt lại mật khẩu, xin hãy bỏ qua email này hoặc liên hệ hỗ trợ ngay lập tức.
                                </p>
                            </div>

                            <p style="margin: 0; font-size: 14px; color: #64748b;">
                                Trân trọng,<br>
                                <strong style="color: #334155;">Đội ngũ chăm sóc</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 18px 30px; text-align: center; font-size: 12px; color: #94a3b8;">
                            Email này được gửi tự động từ hệ thống. Vui lòng không trả lời trực tiếp email này.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

