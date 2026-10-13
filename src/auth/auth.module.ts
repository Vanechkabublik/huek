// Модуль авторизации. Всего 6 файлов:
//   auth.module.ts      — этот файл, собирает всё вместе
//   auth.controller.ts  — эндпоинты /auth/*
//   auth.service.ts     — регистрация / логин / логаут, выпуск токена, cookie
//   auth.guard.ts       — проверка токена и ролей на КАЖДОМ запросе
//   auth.decorators.ts  — @Public(), @Roles(), @CurrentUser()
//   auth.dto.ts         — валидация тела запросов
//
// Нужные переменные в .env:
//   JWT_SECRET=любая-длинная-случайная-строка
//   JWT_EXPIRES_IN=1d          (необязательно, по умолчанию 1d; формат: 15m, 12h, 7d или секунды)

import { Module } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { APP_GUARD } from '@nestjs/core';
import { JwtModule, JwtSignOptions } from '@nestjs/jwt';
import { TypeOrmModule } from '@nestjs/typeorm';

import { User } from '../user/user.entity';
import { AuthController } from './auth.controller';
import { AuthGuard } from './auth.guard';
import { AuthService } from './auth.service';

@Module({
  imports: [
    TypeOrmModule.forFeature([User]),
    // Секрет и срок жизни задаём один раз здесь — JwtService
    // использует их и при подписи (service), и при проверке (guard).
    JwtModule.registerAsync({
      inject: [ConfigService],
      useFactory: (config: ConfigService) => ({
        secret: config.getOrThrow<string>('JWT_SECRET'),
        signOptions: {
          expiresIn: config.get(
            'JWT_EXPIRES_IN',
            '1d',
          ) as JwtSignOptions['expiresIn'],
        },
      }),
    }),
  ],
  controllers: [AuthController],
  providers: [
    AuthService,
    // APP_GUARD делает guard глобальным: он защищает ВСЕ роуты во ВСЁМ приложении,
    // а не только в этом модуле. Открыть роут — @Public().
    { provide: APP_GUARD, useClass: AuthGuard },
  ],
})
export class AuthModule {}
