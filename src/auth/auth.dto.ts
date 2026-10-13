// DTO — что принимаем в теле запросов /auth/register и /auth/login.
// Валидирует глобальный ValidationPipe из main.ts: при ошибке клиент получит 400
// с описанием, а лишние поля (whitelist: true) будут молча выкинуты.

import { IsEmail, IsNotEmpty, IsString, MinLength } from 'class-validator';

export class RegisterDto {
  @IsString()
  @IsNotEmpty()
  name: string;

  @IsEmail()
  email: string;

  @IsString()
  @MinLength(6)
  password: string;
}

export class LoginDto {
  @IsEmail()
  email: string;

  @IsString()
  @IsNotEmpty()
  password: string;
}
